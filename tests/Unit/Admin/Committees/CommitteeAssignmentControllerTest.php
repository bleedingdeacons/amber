<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\Committees;

use Amber\Admin\Committees\CommitteeAssignmentController;
use Amber\Tests\AmberTestCase;
use Brain\Monkey\Functions;
use Unity\Committees\Interfaces\Committee;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;

/**
 * Tests for the committee assignment endpoint.
 *
 * This is the only thing in the suite that writes committee membership, and it
 * is reachable over admin-ajax, so the cases that matter are the refusals: a
 * caller without the capability, an id that is not a member, and a copy to
 * Unassigned. The happy paths matter mostly for one thing — that a move removes
 * the source and a copy does not.
 *
 * @covers \Amber\Admin\Committees\CommitteeAssignmentController
 */
class CommitteeAssignmentControllerTest extends AmberTestCase
{
    private CommitteeAssignmentController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->createMock(Configuration::class);
        $config->method('getConfig')->willReturnCallback(
            static fn (string $key): array => $key === Committee::class
                ? ['TAXONOMY' => 'intergroup-committee']
                : ['POST_TYPE' => 'intergroup-member']
        );

        $this->controller = new CommitteeAssignmentController($config);

        Functions\when('check_ajax_referer')->justReturn(true);

        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];

        parent::tearDown();
    }

    /**
     * wp_send_json_* halt the request in WordPress. Brain Monkey's stubs simply
     * return, so each test records what was sent and asserts on it.
     *
     * @param array<string, mixed> $post
     * @return array{success: bool, data: mixed}
     */
    private function dispatch(array $post): array
    {
        $_POST = $post;

        $sent = ['success' => false, 'data' => null];

        Functions\when('wp_send_json_success')->alias(function ($data = null) use (&$sent): void {
            $sent = ['success' => true, 'data' => $data];
            throw new StopAjax();
        });

        Functions\when('wp_send_json_error')->alias(function ($data = null) use (&$sent): void {
            $sent = ['success' => false, 'data' => $data];
            throw new StopAjax();
        });

        try {
            $this->controller->handle();
        } catch (StopAjax) {
            // The real functions exit; this is how the stub models that.
        }

        return $sent;
    }

    /**
     * @test
     */
    public function it_refuses_a_caller_without_the_capability(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $result = $this->dispatch(['member' => 31, 'target' => 13, 'source' => 12]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not allowed', $result['data']['message']);
    }

    /**
     * @test
     */
    public function it_refuses_an_id_that_is_not_a_member(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_post_type')->justReturn('intergroup-position');

        $result = $this->dispatch(['member' => 88, 'target' => 13, 'source' => 12]);

        $this->assertFalse($result['success']);
        $this->assertSame('That is not a member.', $result['data']['message']);
    }

    /**
     * @test
     */
    public function it_refuses_a_copy_to_unassigned(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_post_type')->justReturn('intergroup-member');

        $result = $this->dispatch(['member' => 31, 'target' => 0, 'source' => 12, 'mode' => 'copy']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('cannot be copied to Unassigned', $result['data']['message']);
    }

    /**
     * @test
     */
    public function a_move_drops_the_source_and_adds_the_target(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_post_type')->justReturn('intergroup-member');
        Functions\when('wp_get_object_terms')->justReturn([12, 99]);

        $written = null;
        Functions\when('wp_set_object_terms')->alias(
            function (int $id, array $terms, string $tax, bool $append) use (&$written) {
                $written = ['id' => $id, 'terms' => $terms, 'tax' => $tax, 'append' => $append];
                return $terms;
            }
        );

        $result = $this->dispatch(['member' => 31, 'target' => 13, 'source' => 12, 'mode' => 'move']);

        $this->assertTrue($result['success']);
        $this->assertSame(31, $written['id']);
        $this->assertSame('intergroup-committee', $written['tax']);
        $this->assertFalse($written['append'], 'the whole set is written, not appended');

        sort($written['terms']);
        $this->assertSame([13, 99], $written['terms'], 'source dropped, unrelated membership kept');
    }

    /**
     * @test
     */
    public function a_copy_keeps_the_source(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_post_type')->justReturn('intergroup-member');
        Functions\when('wp_get_object_terms')->justReturn([12]);

        $written = null;
        Functions\when('wp_set_object_terms')->alias(
            function (int $id, array $terms) use (&$written) {
                $written = $terms;
                return $terms;
            }
        );

        $result = $this->dispatch(['member' => 31, 'target' => 13, 'source' => 12, 'mode' => 'copy']);

        $this->assertTrue($result['success']);

        sort($written);
        $this->assertSame([12, 13], $written);
    }

    /**
     * Dragging someone onto a committee they are already in should not write a
     * duplicate row.
     *
     * @test
     */
    public function an_existing_membership_is_not_duplicated(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_post_type')->justReturn('intergroup-member');
        Functions\when('wp_get_object_terms')->justReturn([13]);

        $written = null;
        Functions\when('wp_set_object_terms')->alias(
            function (int $id, array $terms) use (&$written) {
                $written = $terms;
                return $terms;
            }
        );

        $this->dispatch(['member' => 31, 'target' => 13, 'source' => 0, 'mode' => 'copy']);

        $this->assertSame([13], $written);
    }

    /**
     * A move to Unassigned is the one case that legitimately writes an empty
     * set, and it must not be mistaken for a failure.
     *
     * @test
     */
    public function a_move_to_unassigned_clears_the_only_membership(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_post_type')->justReturn('intergroup-member');
        Functions\when('wp_get_object_terms')->justReturn([12]);

        $written = null;
        Functions\when('wp_set_object_terms')->alias(
            function (int $id, array $terms) use (&$written) {
                $written = $terms;
                return $terms;
            }
        );

        $result = $this->dispatch(['member' => 31, 'target' => 0, 'source' => 12, 'mode' => 'move']);

        $this->assertTrue($result['success']);
        $this->assertSame([], $written);
    }

    /*
     * There is deliberately no test for a forged target id.
     *
     * wp_set_object_terms() validates every id against the taxonomy and returns
     * a WP_Error for anything that is not one of its terms, which is what makes
     * the endpoint safe against a hand-crafted POST — the controller never has
     * to enumerate valid committees itself. But bleedingdeacons/wp-mocks
     * declares the stub as `: array`, and Patchwork replaces a function's body
     * while keeping its signature, so returning a WP_Error from it is a
     * TypeError inside the stub rather than a value the controller ever sees.
     *
     * The guard is right for production and unreachable from this suite. The
     * same limitation, for the same reason, is documented in
     * tsml-for-unity's TsmlCommitteeRepositoryTest against
     * wp_get_object_terms(). Don't delete either for being uncovered.
     */
}

/**
 * Stands in for the exit() inside wp_send_json_*.
 */
class StopAjax extends \RuntimeException
{
}
