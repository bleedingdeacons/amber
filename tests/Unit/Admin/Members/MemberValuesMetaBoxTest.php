<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\Members;

use Amber\Admin\Members\MemberValuesMetaBox;
use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use Unity\Testing\Doubles\MemberStub;
use WP_Post;

/*
 * Tests for the "Extended values" box on the member edit screen.
 *
 * The values it lists are written by other plugins outside ACF, so this box
 * is the only place an administrator sees them. What matters is that every
 * value is shown, escaped, and that nothing is shown to somebody who cannot
 * edit the member.
 */

covers(MemberValuesMetaBox::class);

beforeEach(function () {
    $config = $this->createMock(Configuration::class);
    $config->method('getConfig')->willReturnCallback(
        static fn (string $key): array => $key === Member::class ? ['POST_TYPE' => 'intergroup-member'] : []
    );

    $this->repository = new InMemoryMemberRepository([
        new MemberStub(id: 42, extendedValues: ['Zeta' => 'z', 'AppleUserId' => '001234.abcdef.5678']),
        new MemberStub(id: 43),
        new MemberStub(id: 44, extendedValues: ['Hostile' => '<script>alert(1)</script>']),
    ]);

    $this->box = new MemberValuesMetaBox($config, $this->repository);

    $this->render = function (int $id): string {
        ob_start();
        $this->box->render(new WP_Post(['ID' => $id]));

        return (string) ob_get_clean();
    };
});

it('hooks the member post type\'s meta boxes', function () {
    expect(has_action('add_meta_boxes_intergroup-member', [$this->box, 'addMetaBox']))->not->toBeFalse();
});

it('adds a side box for someone who can edit the member', function () {
    Functions\expect('add_meta_box')
        ->once()
        ->with(MemberValuesMetaBox::BOX_ID, 'Extended values', [$this->box, 'render'], 'intergroup-member', 'side', 'low');

    $this->box->addMetaBox(new WP_Post(['ID' => 42]));
});

it('adds nothing for someone who cannot edit the member', function () {
    WpState::$deniedCaps = ['edit_post'];
    Functions\expect('add_meta_box')->never();

    $this->box->addMetaBox(new WP_Post(['ID' => 42]));
});

it('lists every extended value, sorted by key', function () {
    $html = ($this->render)(42);

    expect($html)->toContain('AppleUserId')
        ->and($html)->toContain('001234.abcdef.5678')
        ->and($html)->toContain('Zeta')
        ->and(strpos($html, 'AppleUserId'))->toBeLessThan(strpos($html, 'Zeta'));
});

it('escapes what it shows', function () {
    $html = ($this->render)(44);

    expect($html)->not->toContain('<script>')
        ->and($html)->toContain('&lt;script&gt;');
});

it('says so when there are none', function () {
    expect(($this->render)(43))->toContain('No extended values.');
});

it('says so for a post that is not a member', function () {
    expect(($this->render)(999))->toContain('No extended values.');
});
