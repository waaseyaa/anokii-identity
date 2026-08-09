<?php

declare(strict_types=1);

namespace Anokii\Identity\Controller;

use Anokii\Identity\Entity\Pillar;
use Anokii\Identity\PillarService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Community\CommunityContextInterface;

/** Authenticated, read-only Identity surface for an existing Waaseyaa host. */
final readonly class HostIdentityController
{
    public function __construct(
        private EntityTypeManager $entities,
        private EntityAccessHandler $access,
        private CommunityContextInterface $community,
    ) {}

    public function index(Request $request): Response
    {
        $principal = $request->attributes->get('_account');
        if (!$principal instanceof AuthorizationPrincipalInterface || !$principal->isAuthenticated()) {
            return new Response(
                'Authentication required.',
                Response::HTTP_UNAUTHORIZED,
                self::privateHeaders('text/plain; charset=UTF-8'),
            );
        }

        $communityId = $this->community->get();
        if (!$this->community->isActive() || !is_string($communityId) || trim($communityId) === '') {
            return new Response(
                'Identity is unavailable without an active community.',
                Response::HTTP_SERVICE_UNAVAILABLE,
                self::privateHeaders('text/plain; charset=UTF-8'),
            );
        }
        if ($principal->communityId() === null || !hash_equals($communityId, $principal->communityId())) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND, self::privateHeaders('text/plain; charset=UTF-8'));
        }

        $pillars = [];
        foreach ($this->entities->getRepository('identity_pillar')->findBy(['langcode' => PillarService::DEFAULT_LANGCODE]) as $entity) {
            if (!$entity instanceof Pillar || !$this->access->check($entity, 'view', $principal)->isAllowed()) {
                continue;
            }
            $pillars[] = [
                'title' => $entity->getTitle(),
                'section' => $entity->getSection(),
                'status' => $entity->getStatus(),
            ];
        }
        usort($pillars, static fn(array $left, array $right): int => [$left['section'], $left['title']] <=> [$right['section'], $right['title']]);

        $items = $pillars === []
            ? '<p class="empty">No Identity pillars have been created for this community.</p>'
            : '<ul>' . implode('', array_map(
                static fn(array $pillar): string => sprintf(
                    '<li><strong>%s</strong><span>%s</span><small>%s</small></li>',
                    self::escape($pillar['title']),
                    self::escape($pillar['section']),
                    self::escape($pillar['status']),
                ),
                $pillars,
            )) . '</ul>';

        $html = sprintf(<<<'HTML'
            <!doctype html>
            <html lang="en">
            <head>
              <meta charset="utf-8">
              <meta name="viewport" content="width=device-width, initial-scale=1">
              <meta name="robots" content="noindex, nofollow">
              <title>Identity | Anokii</title>
              <style>
                :root{color-scheme:light;--ink:#123332;--teal:#0f766e;--line:#cbdedb;--bg:#f4f8f7}
                *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.55 system-ui,sans-serif}
                main{max-width:64rem;margin:auto;padding:clamp(1.25rem,4vw,3.5rem)}
                header{border-left:.45rem solid var(--teal);padding-left:1rem;margin-bottom:2rem}
                h1{font-size:clamp(2rem,7vw,3.75rem);line-height:1;margin:.2rem 0}.meta{color:#496866}
                ul{display:grid;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr));gap:1rem;padding:0;list-style:none}
                li,.empty{background:white;border:1px solid var(--line);border-radius:.75rem;padding:1rem;box-shadow:0 2px 8px #1233320d}
                li span,li small{display:block}li span{margin-top:.35rem}li small{color:#496866;text-transform:capitalize}
                a{color:var(--teal)}a:focus-visible{outline:3px solid #f4b942;outline-offset:3px}
              </style>
            </head>
            <body><main>
              <header><p>Anokii capability</p><h1>Identity</h1><p class="meta">Active community: %s</p></header>
              <section aria-labelledby="pillars"><h2 id="pillars">Identity pillars</h2>%s</section>
              <p><a href="/admin">Return to administration</a></p>
            </main></body></html>
            HTML,
            self::escape($communityId),
            $items,
        );

        return new Response($html, Response::HTTP_OK, self::privateHeaders('text/html; charset=UTF-8'));
    }

    /** @return array<string, string> */
    private static function privateHeaders(string $contentType): array
    {
        return [
            'Content-Type' => $contentType,
            'X-Robots-Tag' => 'noindex, nofollow',
            'Cache-Control' => 'private, no-store',
        ];
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
