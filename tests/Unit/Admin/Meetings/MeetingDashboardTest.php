<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\Meetings;

use Amber\Admin\Meetings\MeetingDashboard;
use Amber\Managers\MeetingReconciler;
use Amber\Models\ReconciliationResult;
use BleedingDeacons\WpMocks\WpState;
use Unity\Contacts\Interfaces\Contact;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Locations\Interfaces\Location;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Meetings\Interfaces\MeetingRepository;

/*
 * Tests for the Groups & Meetings dashboard widget.
 *
 * This is the widest single render in Amber: for every meeting it draws a card
 * carrying the local data (time, group, location, contacts, email) alongside a
 * reconciliation verdict against the national AAGBDB listing. That verdict has
 * five shapes — a confident match, a partial match with caveats, a match to a
 * now-closed national listing, a day/time-only "possible", and local-only —
 * and each paints a different badge, note and status pill. The test feeds a
 * hand-built ReconciliationResult covering all five so every branch of the card
 * renderer is walked, then checks the graceful paths: no reconciler, no
 * meetings, and the screen gate on the styles and scripts.
 */

covers(MeetingDashboard::class);

beforeEach(function () {
    $this->meetingRepository = $this->createMock(MeetingRepository::class);
    $this->groupRepository   = $this->createMock(GroupRepository::class);

    $this->dashboard = function (?MeetingReconciler $reconciler = null): MeetingDashboard {
        return new MeetingDashboard($this->meetingRepository, $this->groupRepository, $reconciler);
    };

    $this->contact = function (string $name, string $phone): Contact {
        $contact = $this->createMock(Contact::class);
        $contact->method('getName')->willReturn($name);
        $contact->method('getPhone')->willReturn($phone);

        return $contact;
    };

    $this->location = function (string $name, string $address = ''): Location {
        $location = $this->createMock(Location::class);
        $location->method('getName')->willReturn($name);
        $location->method('getFormattedAddress')->willReturn($address);

        return $location;
    };

    /**
     * @param array<string, mixed> $opts
     */
    $this->meeting = function (int $id, array $opts = []): Meeting {
        $defaults = [
            'day'        => 1,
            'time'       => '19:00',
            'endTime'    => '20:30',
            'name'       => 'Meeting ' . $id,
            'online'     => false,
            'onlineLink' => '',
            'location'   => null,
            'contacts'   => [],
            'groupId'    => null,
        ];
        $o = array_merge($defaults, $opts);

        $meeting = $this->createMock(Meeting::class);
        $meeting->method('getId')->willReturn($id);
        $meeting->method('getDay')->willReturn($o['day']);
        $meeting->method('getTime')->willReturn($o['time']);
        $meeting->method('getEndTime')->willReturn($o['endTime']);
        $meeting->method('getName')->willReturn($o['name']);
        $meeting->method('isOnline')->willReturn($o['online']);
        $meeting->method('getOnlineLink')->willReturn($o['onlineLink']);
        $meeting->method('getLocation')->willReturn($o['location']);
        $meeting->method('getContacts')->willReturn($o['contacts']);
        $meeting->method('getMeta')->willReturn(
            $o['groupId'] === null ? [] : ['group_id' => [(string) $o['groupId']]]
        );

        return $meeting;
    };

    $this->group = function (int $id, string $title, string $email = '', array $contacts = []): Group {
        $group = $this->createMock(Group::class);
        $group->method('getId')->willReturn($id);
        $group->method('getTitle')->willReturn($title);
        $group->method('getEmail')->willReturn($email);
        $group->method('getContacts')->willReturn($contacts);

        return $group;
    };

    /** A reconciler whose result covers every verdict type. */
    $this->fullReconciler = function (): MeetingReconciler {
        $result = new ReconciliationResult(
            matches: [
                ['local_id' => 1, 'national_name' => 'National A', 'national_id' => 10, 'national_address' => '1 High St', 'national_postcode' => 'BS1 1AA', 'national_status' => 'Open', 'score' => 0.95, 'notes' => []],
                ['local_id' => 2, 'national_name' => 'National B', 'national_id' => 11, 'national_address' => '', 'national_postcode' => '', 'national_status' => 'Open Again', 'score' => 0.8, 'notes' => ['End time mismatch']],
            ],
            possibles: [
                ['local_id' => 4, 'national_name' => 'National D', 'national_id' => 13, 'national_address' => '', 'national_postcode' => 'BS4 4DD', 'national_status' => 'Open'],
            ],
            localOnly: [
                ['id' => 5, 'reason' => 'No national candidate'],
            ],
            nationalOnly: [],
            summary: ['total' => 6],
            closedMatches: [
                ['local_id' => 3, 'national_name' => 'National C', 'national_id' => 12, 'national_address' => '3 Low St', 'national_postcode' => 'BS3 3CC', 'national_status' => 'Closed', 'score' => 0.9, 'notes' => ['Weak match']],
            ],
        );

        $reconciler = $this->createMock(MeetingReconciler::class);
        $reconciler->method('reconcile')->willReturn($result);

        return $reconciler;
    };
});

// ── the full render ──────────────────────────────────────────────
it('paints a card for every reconciliation verdict', function () {
    $groupA = ($this->group)(100, 'Tuesday Group', 'tues@example.test', [
        ($this->contact)('Alex', '0117 000 0001'),
        ($this->contact)('Sam', ''),          // name only
        ($this->contact)('', '0117 000 0003'), // phone only
        ($this->contact)('Jo', '0117 000 0004'), // 4th — trimmed by the 3-contact cap
    ]);

    $this->meetingRepository->method('findAll')->willReturn([
        ($this->meeting)(1, ['day' => 2, 'location' => ($this->location)('Church Hall', '1 High St, Bristol'), 'groupId' => 100]),
        ($this->meeting)(2, ['day' => 2, 'time' => '', 'online' => true, 'onlineLink' => 'https://zoom.example', 'groupId' => 100]),
        ($this->meeting)(3, ['day' => 3, 'endTime' => '', 'online' => true, 'onlineLink' => '']),
        ($this->meeting)(4, ['day' => 4, 'location' => null, 'groupId' => 200]),
        ($this->meeting)(5, ['day' => 5, 'groupId' => 300]),
        ($this->meeting)(6, ['day' => 0, 'groupId' => 0]),
    ]);

    $this->groupRepository->method('findById')->willReturnMap([
        [100, $groupA],
        [200, ($this->group)(200, 'No-Email Group')],
        [300, ($this->group)(300, 'Emailed Group', 'grp@example.test')],
    ]);

    $html = $this->capture(fn () => ($this->dashboard)(($this->fullReconciler)())->renderDashboardWidget());

    expect($html)
        // Confident match: plain AAGBDB badge and an Open status pill.
        ->toContain('recon-matched')
        ->toContain('status-open')
        // Partial match carries its caveat.
        ->toContain('recon-partial')
        ->toContain('End time mismatch')
        // Closed national match and its closed pill.
        ->toContain('recon-closed')
        ->toContain('status-closed')
        // Possible and local-only.
        ->toContain('recon-possible')
        ->toContain('recon-missing')
        ->toContain('No national candidate')
        // Local data made it in.
        ->toContain('Church Hall')
        ->toContain('tues@example.test')
        ->toContain('Alex')
        // The 4th contact is past the 3-contact cap.
        ->not->toContain('0117 000 0004')
        // Online meeting with a link, and one without.
        ->toContain('Online Meeting')
        ->toContain('meeting-online-label');
});

// ── graceful paths ───────────────────────────────────────────────
it('says no meetings found on an empty site', function () {
    $this->meetingRepository->method('findAll')->willReturn([]);

    expect($this->capture(fn () => ($this->dashboard)()->renderDashboardWidget()))
        ->toContain('No meetings found');
});

it('still renders the cards with dashes without a reconciler', function () {
    // Concordance absent: every national field degrades to an em dash
    // rather than the widget failing.
    $this->meetingRepository->method('findAll')->willReturn([
        ($this->meeting)(1, ['groupId' => null, 'contacts' => []]),
    ]);

    $html = $this->capture(fn () => ($this->dashboard)(null)->renderDashboardWidget());

    expect($html)->toContain('meeting-card')
        ->toContain('National Listing')
        ->not->toContain('recon-matched');
});

it('swallows a reconciler that throws', function () {
    $reconciler = $this->createMock(MeetingReconciler::class);
    $reconciler->method('reconcile')->willThrowException(new \RuntimeException('API down'));

    $this->meetingRepository->method('findAll')->willReturn([($this->meeting)(1)]);

    $html = $this->capture(fn () => ($this->dashboard)($reconciler)->renderDashboardWidget());

    // Reconciliation failed, but the dashboard still drew the card.
    expect($html)->toContain('meeting-card');
});

it('registers the widget', function () {
    ($this->dashboard)()->registerDashboardWidget();

    expect(WpState::$widgets)->toHaveKey('groups_meetings_dashboard');
});

it('loads styles and scripts only on the dashboard', function () {
    $dashboard = ($this->dashboard)();

    $this->setScreen('dashboard', 'dashboard');
    expect($this->capture(fn () => $dashboard->addDashboardStyles()))->toContain('<style>')
        ->and($this->capture(fn () => $dashboard->addDashboardScripts()))->toContain('<script>');

    $this->setScreen('edit-post', 'edit', 'post');
    expect($this->capture(fn () => $dashboard->addDashboardStyles()))->toBe('')
        ->and($this->capture(fn () => $dashboard->addDashboardScripts()))->toBe('');
});
