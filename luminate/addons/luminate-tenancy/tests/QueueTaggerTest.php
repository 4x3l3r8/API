<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Tests;

use Luminate\Tenancy\Queue\QueueTagger;
use Luminate\Tenancy\Queue\TagRejected;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueueTaggerTest extends TestCase
{
    private const UUID = '11111111-2222-3333-4444-555555555555';

    private function tagger(array $keys = [TokenFactory::KEY]): QueueTagger
    {
        return new QueueTagger($keys);
    }

    private function payload(?array $tag = null, string $uuid = self::UUID): array
    {
        return ['uuid' => $uuid] + ($tag ?? []);
    }

    private function assertRefused(callable $fn, string $reason): void
    {
        try {
            $fn();
            $this->fail('expected the tag to be refused');
        } catch (TagRejected $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function testATaggedJobVerifiesToItsTenant(): void
    {
        $t = $this->tagger();
        $this->assertSame('t-alpha', $t->verify($this->payload($t->tag(self::UUID, 't-alpha'))));
    }

    public function testThePreviousKeyIsAcceptedForRotation(): void
    {
        $old = $this->tagger([TokenFactory::PREVIOUS_KEY]);
        $rotated = $this->tagger([TokenFactory::KEY, TokenFactory::PREVIOUS_KEY]);
        $this->assertSame('t-alpha', $rotated->verify($this->payload($old->tag(self::UUID, 't-alpha'))));
    }

    public function testARetiredKeyIsRefused(): void
    {
        $old = $this->tagger([TokenFactory::PREVIOUS_KEY]);
        $this->assertRefused(fn () => $this->tagger()->verify($this->payload($old->tag(self::UUID, 't-alpha'))), 'bad_signature');
    }

    public function testNoTagIsRefusedAsMissing(): void
    {
        $this->assertRefused(fn () => $this->tagger()->verify(['uuid' => self::UUID]), 'missing');
        $this->assertRefused(fn () => $this->tagger()->verify(['uuid' => self::UUID, QueueTagger::PAYLOAD_KEY => null]), 'missing');
    }

    public function testRelabellingTheTenantBreaksTheSignature(): void
    {
        $tag = $this->tagger()->tag(self::UUID, 't-alpha');
        $tag[QueueTagger::PAYLOAD_KEY]['tid'] = 't-beta';
        $this->assertRefused(fn () => $this->tagger()->verify($this->payload($tag)), 'bad_signature');
    }

    public function testATagCopiedOntoAnotherJobIsRefused(): void
    {
        $tag = $this->tagger()->tag('99999999-0000-0000-0000-000000000000', 't-alpha');
        $this->assertRefused(fn () => $this->tagger()->verify($this->payload($tag)), 'bad_signature');
    }

    public function testATagSignedWithTheWrongKeyIsRefused(): void
    {
        $forger = $this->tagger([str_repeat('x', 40)]);
        $this->assertRefused(fn () => $this->tagger()->verify($this->payload($forger->tag(self::UUID, 't-alpha'))), 'bad_signature');
    }

    #[DataProvider('malformedTags')]
    public function testMalformedTagsAreRefused(mixed $tag): void
    {
        $this->assertRefused(fn () => $this->tagger()->verify(['uuid' => self::UUID, QueueTagger::PAYLOAD_KEY => $tag]), 'malformed');
    }

    public static function malformedTags(): array
    {
        return [
            'string' => ['not-an-object'],
            'number' => [42],
            'empty array' => [[]],
            'no sig' => [['tid' => 't-alpha']],
            'no tid' => [['sig' => 'abc']],
            'sig not string' => [['tid' => 't-alpha', 'sig' => ['x']]],
            'tid with quote' => [['tid' => "t'; drop table users;--", 'sig' => 'abc']],
            'tid with slash' => [['tid' => 'a/b', 'sig' => 'abc']],
            'tid too long' => [['tid' => str_repeat('a', 65), 'sig' => 'abc']],
        ];
    }

    public function testAPayloadWithoutAUuidIsRefused(): void
    {
        $this->assertRefused(fn () => $this->tagger()->verify([QueueTagger::PAYLOAD_KEY => ['tid' => 't-alpha', 'sig' => 'abc']]), 'malformed');
    }

    public function testNoKeyFailsClosedBothWays(): void
    {
        $this->assertRefused(fn () => $this->tagger([])->tag(self::UUID, 't-alpha'), 'misconfigured');
        $this->assertRefused(fn () => $this->tagger([])->verify($this->payload($this->tagger()->tag(self::UUID, 't-alpha'))), 'misconfigured');
    }

    public function testAShortKeyFailsClosed(): void
    {
        $this->assertRefused(fn () => $this->tagger(['short'])->tag(self::UUID, 't-alpha'), 'misconfigured');
    }

    public function testTheQueueKeyIsNotTheContextKey(): void
    {
        // A context-token signature (HMAC over a different string with the raw key) must not validate as a queue tag.
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', self::UUID . "\nt-alpha", TokenFactory::KEY, true)), '+/', '-_'), '=');
        $this->assertRefused(fn () => $this->tagger()->verify(['uuid' => self::UUID, QueueTagger::PAYLOAD_KEY => ['tid' => 't-alpha', 'sig' => $sig]]), 'bad_signature');
    }
}
