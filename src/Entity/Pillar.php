<?php

declare(strict_types=1);

namespace Anokii\Identity\Entity;

use Anokii\Core\Entity\SovereignMetadataTrait;
use Anokii\Core\Support\Values;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\RevisionableInterface;

/**
 * A pillar in the Anokii Identity Workspace.
 *
 * This is the entity-native rebuild of the Identity tool: it replaces a raw
 * `pillar` table prototype with a registered, revisionable entity (see the
 * instance's entity-type registration), so identity content gains full history.
 * Each edit (a status or notes change) sets the changed fields plus the editor
 * stamp and saves, which records a new revision; the repository's listRevisions()
 * is the per-pillar history.
 *
 * The stable public handle is `pid` (e.g. "purpose", "moat"): a short string the
 * UI and the chat agent address a pillar by. The entity id/uuid are internal.
 *
 * Fields (all carried in the automatic _data blob, snapshotted per revision):
 *   pid                 stable string key (addressable handle)
 *   section             grouping key (instance-defined taxonomy)
 *   title               pillar title (the label key)
 *   now_label           label for the "Now" block (varies per pillar)
 *   body                the "Now" content
 *   is_quote            render body as a quotation (0/1)
 *   decide_label        label for the decision callout (Decide, Rewrite, ...)
 *   decision            the decision / next-step text
 *   status              maturity: defined | draft | work | gap
 *   notes               editorial notes / drafts (free text, can be long)
 *   pills               tag chips: list of {t, cyan}
 *   is_full             render full-width (0/1)
 *   sort_order          ordering within the workspace
 *   editor_label        display name of who made the current revision (cache;
 *                       the acting uid is the framework's revision_author,
 *                       alpha.205+ — old revisions keep editor_uid in _data)
 *   updated_at          last-edited stamp (preserved verbatim on migration)
 */
#[ContentEntityType(id: 'identity_pillar', label: 'Identity pillar', description: 'A revisionable Identity Workspace pillar with status, notes, and history.')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title', revision: 'revision_id', langcode: 'langcode', default_langcode: 'default_langcode')]
final class Pillar extends ContentEntityBase implements RevisionableInterface
{
    use SovereignMetadataTrait;

    #[Field(read: FieldReadLevel::Public)] public string $pid = '';
    #[Field(read: FieldReadLevel::Public)] public string $section = '';
    #[Field(read: FieldReadLevel::Public)] public string $title = '';
    #[Field(required: false, read: FieldReadLevel::Public)] public string $now_label = '';
    #[Field(type: 'text', required: false, read: FieldReadLevel::Public)] public string $body = '';
    #[Field(read: FieldReadLevel::Public)] public bool $is_quote = false;
    #[Field(required: false, read: FieldReadLevel::Public)] public string $decide_label = '';
    #[Field(type: 'text', required: false, read: FieldReadLevel::Public)] public string $decision = '';
    #[Field(read: FieldReadLevel::Public)] public string $status = 'draft';
    #[Field(type: 'text', required: false, read: FieldReadLevel::Public)] public string $notes = '';
    /** @var list<array{t:string, cyan:bool}> */
    #[Field(type: 'json', required: false, read: FieldReadLevel::Public)] public array $pills = [];
    #[Field(read: FieldReadLevel::Public)] public bool $is_full = false;
    #[Field(read: FieldReadLevel::Public)] public int $sort_order = 0;
    #[Field(read: FieldReadLevel::Public)] public int $editor_uid = 0;
    #[Field(read: FieldReadLevel::Public)] public string $editor_label = '';
    #[Field(read: FieldReadLevel::Public)] public string $updated_at = '';
    #[Field(read: FieldReadLevel::Public)] public string $revision_log = '';

    /** Valid maturity statuses, in display order. */
    public const STATUSES = ['defined', 'draft', 'work', 'gap'];

    public function getPid(): string
    {
        return Values::str($this->get('pid'));
    }

    public function setPid(string $pid): static
    {
        $this->set('pid', $pid);

        return $this;
    }

    public function getSection(): string
    {
        return Values::str($this->get('section'));
    }

    public function getTitle(): string
    {
        return Values::str($this->get('title'));
    }

    public function getNowLabel(): string
    {
        return Values::str($this->get('now_label'));
    }

    public function getBody(): string
    {
        return Values::str($this->get('body'));
    }

    public function isQuote(): bool
    {
        return Values::int($this->get('is_quote')) === 1;
    }

    public function getDecideLabel(): string
    {
        return Values::str($this->get('decide_label'));
    }

    public function getDecision(): string
    {
        return Values::str($this->get('decision'));
    }

    public function getStatus(): string
    {
        return Values::str($this->get('status'));
    }

    public function setStatus(string $status): static
    {
        $this->set('status', $status);

        return $this;
    }

    public function getNotes(): string
    {
        return Values::str($this->get('notes'));
    }

    public function setNotes(string $notes): static
    {
        $this->set('notes', $notes);

        return $this;
    }

    /** @return list<array{t:string,cyan:bool}> */
    public function getPills(): array
    {
        $out = [];
        foreach (Values::mapList($this->get('pills')) as $pill) {
            $out[] = ['t' => Values::str($pill['t'] ?? null), 'cyan' => Values::bool($pill['cyan'] ?? null)];
        }

        return $out;
    }

    public function isFull(): bool
    {
        return Values::int($this->get('is_full')) === 1;
    }

    public function getSortOrder(): int
    {
        return Values::int($this->get('sort_order'));
    }

    /**
     * The acting account uid for this revision: the framework's
     * revision_author (recorded automatically since alpha.205, hydrated on
     * loadRevision()/listRevisions()), falling back to the editor_uid the app
     * snapshotted into _data before the framework owned authorship. 0 means
     * anonymous/system (the pre-upgrade seed/migration convention).
     */
    public function getEditorUid(): int
    {
        $author = $this->revisionMetadata()?->revisionAuthor;
        if ($author !== null) {
            return $author;
        }

        return Values::int($this->get('editor_uid'));
    }

    public function getEditorLabel(): string
    {
        return Values::str($this->get('editor_label'));
    }

    /**
     * Stamp the display name of the editor. The acting uid is NOT written
     * here any more: the framework records it as revision_author on save.
     */
    public function setEditorLabel(string $label): static
    {
        $this->set('editor_label', $label);

        return $this;
    }

    public function getUpdatedAt(): string
    {
        return Values::str($this->get('updated_at'));
    }

    public function setUpdatedAt(string $updatedAt): static
    {
        $this->set('updated_at', $updatedAt);

        return $this;
    }

    /** When this revision was created, from the revision metadata. */
    public function getRevisionCreatedAt(): ?\DateTimeImmutable
    {
        return $this->revisionMetadata()?->revisionCreatedAt;
    }

    /**
     * Populate every defining field at once (create / migration path). The
     * caller saves; with revisionDefault the save records the initial revision.
     *
     * @param list<array{t:string,cyan:bool}> $pills
     */
    public function fill(
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
    ): static {
        $this->set('pid', $pid);
        $this->set('section', $section);
        $this->set('title', $title);
        $this->set('now_label', $nowLabel);
        $this->set('body', $body);
        $this->set('is_quote', $isQuote);
        $this->set('decide_label', $decideLabel);
        $this->set('decision', $decision);
        $this->set('status', $status);
        $this->set('notes', $notes);
        $this->set('pills', $pills);
        $this->set('is_full', $isFull);
        $this->set('sort_order', $sortOrder);
        $this->set('editor_uid', 0);
        $this->set('editor_label', $editorLabel);
        $this->set('updated_at', $updatedAt);

        return $this;
    }

    /** Write a short summary into the revision log (what this edit changed). */
    public function recordEdit(string $summary): static
    {
        $this->setRevisionLog($summary);

        return $this;
    }
}
