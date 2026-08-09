<?php

declare(strict_types=1);

namespace Anokii\Identity;

use Anokii\Identity\Entity\Pillar;
use Symfony\Component\Uid\Uuid;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

/**
 * Orchestrates the entity-native Identity Workspace over the framework revision
 * system. A pillar is a revisionable `identity_pillar` entity; each status or
 * notes edit records a new revision (listRevisions() is the history).
 *
 * Two-axis (Phase 2): the pillar is translatable. The default language is the
 * base row; each peer language is a true `(id, langcode)` peer row with its own
 * independent revision history. The translatable fields are `title` and `body`
 * (the moat); everything else is non-translatable workspace state on the base
 * row. Translation edits flow through the framework's unified two-axis save
 * (EntityRepositoryInterface::saveTranslation — peer base row + per-language
 * revision, atomic).
 *
 * This replaces the raw-table repository: the same read/edit surface, now on
 * registered entities with full per-pillar history and attribution.
 *
 * Attribution (alpha.205+): the framework records the acting account uid as
 * revision_author on every save automatically (request-scoped via
 * SessionMiddleware), so this service no longer writes editor_uid — only the
 * human-readable editor_label display cache stays app-side. Old revisions keep
 * their editor_uid snapshot in _data; Pillar::getEditorUid() falls back to it.
 *
 * The peer-language set is supplied by the instance (constructor arg) so the
 * distribution ships no hardcoded language taxonomy; it defaults to no peer
 * languages (single-axis behavior).
 */
final class PillarService
{
    /** Editable maturity statuses. */
    public const STATUSES = Pillar::STATUSES;

    /** The default (canonical) language: the base rows belong to it. */
    public const DEFAULT_LANGCODE = 'en';

    /**
     * Peer languages, in display order: langcode => endonym.
     *
     * @var array<string, string>
     */
    private array $translations;

    /**
     * @param array<string, string> $translations peer languages: langcode => endonym
     */
    public function __construct(
        private readonly ?EntityTypeManager $entityTypeManager,
        array $translations = [],
        private readonly string $communityId = '',
    ) {
        if (trim($communityId) === '') {
            throw new \InvalidArgumentException('PillarService requires an active community id.');
        }
        $this->translations = $translations;
    }

    /**
     * The configured peer languages (langcode => endonym), in display order.
     *
     * @return array<string, string>
     */
    public function translations(): array
    {
        return $this->translations;
    }

    /** @return list<Pillar> all pillars, ordered by sort_order ascending */
    public function listPillars(): array
    {
        $pillars = [];
        // Canonical (default-language) rows only — peer-language rows are
        // overlays addressed per-pillar, not separate pillars in the workspace.
        foreach ($this->pillars()->findBy(['langcode' => self::DEFAULT_LANGCODE]) as $entity) {
            if ($entity instanceof Pillar) {
                $pillars[] = $entity;
            }
        }
        usort($pillars, static fn(Pillar $a, Pillar $b) => $a->getSortOrder() <=> $b->getSortOrder());

        return $pillars;
    }

    public function count(): int
    {
        return count($this->listPillars());
    }

    public function findByPid(string $pid): ?Pillar
    {
        if ($pid === '') {
            return null;
        }
        foreach ($this->pillars()->findBy(['pid' => $pid]) as $entity) {
            if ($entity instanceof Pillar) {
                return $entity;
            }
        }

        return null;
    }

    /**
     * Create a pillar with its initial revision. Used by migration (verbatim
     * import) and the fresh-install seed. The caller supplies the attribution
     * stamp (editorLabel / updatedAt) so a migrated pillar carries the original
     * editor and time, not the import time.
     *
     * @param list<array{t:string,cyan:bool}> $pills
     */
    public function createPillar(
        string $pid,
        string $section,
        string $title,
        string $nowLabel,
        string $body,
        bool $isQuote,
        string $decideLabel,
        string $decision,
        string $status,
        string $notes,
        array $pills,
        bool $isFull,
        int $sortOrder,
        string $editorLabel,
        string $updatedAt,
        string $revisionLog,
    ): Pillar {
        $pillar = new Pillar([
            'uuid' => Uuid::v4()->toRfc4122(),
            'community_id' => $this->communityId,
            'classification_label' => 'nation-restricted',
        ]);
        $pillar->fill(
            $pid,
            $section,
            $title,
            $nowLabel,
            $body,
            $isQuote,
            $decideLabel,
            $decision,
            $status,
            $notes,
            $pills,
            $isFull,
            $sortOrder,
            $editorLabel,
            $updatedAt,
        );
        $pillar->recordEdit($revisionLog);
        $pillar->enforceIsNew();
        $this->pillars()->save($pillar);

        return $pillar;
    }

    /**
     * Apply a status and/or notes edit, recording a new revision with
     * attribution. Returns the stamp (editor + time) plus what changed, or null
     * when the pid is unknown, the status is invalid, or nothing would change.
     *
     * @return array{editor_label:string, updated_at:string, changed:list<string>}|null
     */
    public function update(string $pid, ?string $status, ?string $notes, string $editorLabel): ?array
    {
        $pillar = $this->findByPid($pid);
        if ($pillar === null) {
            return null;
        }

        $changed = [];
        if ($status !== null && $status !== $pillar->getStatus()) {
            if (!in_array($status, Pillar::STATUSES, true)) {
                return null;
            }
            $pillar->setStatus($status);
            $changed[] = 'status';
        }
        if ($notes !== null && $notes !== $pillar->getNotes()) {
            $pillar->setNotes($notes);
            $changed[] = 'notes';
        }
        if ($changed === []) {
            return null;
        }

        $updatedAt = gmdate('Y-m-d\TH:i:s\Z');
        // The acting uid lands in revision_author on save (framework-owned,
        // alpha.205+). NOTE: the workspace form does not send the revision id
        // it was rendered from, so this human path saves without
        // SaveContext::withExpectedRevisionId() — adopting conflict detection
        // here needs a client change and is a documented follow-up (the agent
        // path already states its expectation, see AgentConversation).
        $pillar->setEditorLabel($editorLabel);
        $pillar->setUpdatedAt($updatedAt);
        $pillar->recordEdit($this->summarize($changed, $pillar));
        $this->pillars()->save($pillar);

        return ['editor_label' => $editorLabel, 'updated_at' => $updatedAt, 'changed' => $changed];
    }

    /** @return list<Pillar> revision history for a pillar, newest first */
    public function listHistory(string $pid): array
    {
        $pillar = $this->findByPid($pid);
        if ($pillar === null) {
            return [];
        }
        $history = [];
        foreach ($this->pillars()->listRevisions((string) $pillar->id()) as $rev) {
            if ($rev instanceof Pillar) {
                $history[] = $rev;
            }
        }

        return $history;
    }

    /**
     * Counts per status across all pillars (for the maturity bar).
     *
     * @return array{defined:int, draft:int, work:int, gap:int, total:int}
     */
    public function statusCounts(): array
    {
        $counts = ['defined' => 0, 'draft' => 0, 'work' => 0, 'gap' => 0];
        foreach ($this->listPillars() as $pillar) {
            $s = $pillar->getStatus();
            if (isset($counts[$s])) {
                $counts[$s]++;
            }
        }
        $counts['total'] = $counts['defined'] + $counts['draft'] + $counts['work'] + $counts['gap'];

        return $counts;
    }

    /**
     * @param list<string> $changed
     */
    private function summarize(array $changed, Pillar $pillar): string
    {
        if ($changed === ['status']) {
            return 'Status set to ' . $pillar->getStatus();
        }
        if ($changed === ['notes']) {
            return 'Notes updated';
        }

        return 'Status set to ' . $pillar->getStatus() . ', notes updated';
    }

    /** Whether a langcode is a supported peer language (not the default). */
    public function isTranslationLangcode(string $langcode): bool
    {
        return array_key_exists($langcode, $this->translations);
    }

    /**
     * The current peer-language value of a pillar (its `(id, langcode)` row), or
     * null when that language has not been translated yet.
     */
    public function getTranslation(string $pid, string $langcode): ?Pillar
    {
        if (!$this->isTranslationLangcode($langcode)) {
            return null;
        }
        $pillar = $this->findByPid($pid);
        if ($pillar === null) {
            return null;
        }
        $translated = $this->pillars()->loadTranslation((string) $pillar->id(), $langcode);

        return $translated instanceof Pillar ? $translated : null;
    }

    /**
     * Save a peer-language translation of a pillar's moat fields (title + body):
     * upsert the `(id, langcode)` peer row and record a per-language revision,
     * atomically. Non-translatable workspace state (status, notes, ...) stays on
     * the base row and is untouched. Returns the attribution stamp, or null when
     * the pid is unknown or the langcode is not a supported peer language.
     *
     * @return array{editor_label:string, updated_at:string, revision:int}|null
     */
    public function saveTranslation(
        string $pid,
        string $langcode,
        string $title,
        string $body,
        string $editorLabel,
    ): ?array {
        if (!$this->isTranslationLangcode($langcode)) {
            return null;
        }
        $pillar = $this->findByPid($pid);
        if ($pillar === null) {
            return null;
        }

        $updatedAt = gmdate('Y-m-d\TH:i:s\Z');
        $revision = $this->pillars()->saveTranslation(
            (string) $pillar->id(),
            $langcode,
            [
                // The translatable moat fields, plus the per-language display
                // label so the peer row carries its own editor stamp. The
                // acting uid is recorded as revision_author by the framework
                // (saveTranslation flows through the same author resolution).
                'title' => $title,
                'body' => $body,
                'pid' => $pillar->getPid(),
                'editor_label' => $editorLabel,
                'updated_at' => $updatedAt,
            ],
            $editorLabel !== '' ? $editorLabel . ' edited ' . $this->translations[$langcode] : 'Translation updated',
        );

        return ['editor_label' => $editorLabel, 'updated_at' => $updatedAt, 'revision' => $revision];
    }

    /**
     * Per-language revision history for a pillar's translation, newest first
     * (an independent timeline from the single-axis history).
     *
     * @return list<Pillar>
     */
    public function listTranslationHistory(string $pid, string $langcode): array
    {
        if (!$this->isTranslationLangcode($langcode)) {
            return [];
        }
        $pillar = $this->findByPid($pid);
        if ($pillar === null) {
            return [];
        }
        $history = [];
        foreach ($this->pillars()->listTranslationRevisions((string) $pillar->id(), $langcode) as $rev) {
            if ($rev instanceof Pillar) {
                $history[] = $rev;
            }
        }

        return $history;
    }

    private function pillars(): EntityRepositoryInterface
    {
        if ($this->entityTypeManager === null) {
            throw new \LogicException('PillarService requires a booted kernel (EntityTypeManager).');
        }

        return $this->entityTypeManager->getRepository('identity_pillar');
    }
}
