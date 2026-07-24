<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\Meetings;

use Amber\Admin\Meetings\MeetingDashboard;
use Amber\Managers\MeetingReconciler;
use Amber\Models\ReconciliationResult;
use Amber\Tests\AmberTestCase;
use Amber\Tests\WpState;
use Unity\Contacts\Interfaces\Contact;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Locations\Interfaces\Location;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Meetings\Interfaces\MeetingRepository;

/**
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
 *
 * @covers \Amber\Admin\Meetings\MeetingDashboard
 */
class MeetingDashboardTest extends AmberTestCase
{
    /** @var MeetingRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $meetingRepository;

    /** @var GroupRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $groupRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->meetingRepository = $this->createMock(MeetingRepository::class);
        $this->groupRepository   = $this->createMock(GroupRepository::class);
    }

    private function dashboard(?MeetingReconciler $reconciler = null): MeetingDashboard
    {
        return new MeetingDashboard($this->meetingRepository, $this->groupRepository, $reconciler);
    }

    private function contact(string $name, string $phone): Contact
    {
        $contact = $this->createMock(Contact::class);
        $contact->method('getName')->willReturn($name);
        $contact->method('getPhone')->willReturn($phone);

        return $contact;
    }

    private function location(string $name, string $address = ''): Location
    {
        $location = $this->createMock(Location::class);
        $location->method('getName')->willReturn($name);
        $location->method('getFormattedAddress')->willReturn($address);

        return $location;
    }

    /**
     * @param array<string, mixed> $opts
     */
    private function meeting(int $id, array $opts = []): Meeting
    {
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
    }

    private function group(int $id, string $title, string $email = '', array $contacts = []): Group
    {
        $group = $this->createMock(Group::class);
        $group->method('getId')->willReturn($id);
        $group->method('getTitle')->willReturn($title);
        $group->method('getEmail')->willReturn($email);
        $group->method('getContacts')->willReturn($contacts);

        return $group;
    }

    /** A reconciler whose result covers every verdict type. */
    private function fullReconciler(): MeetingReconciler
    {
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
    }

    // ── the full render ──────────────────────────────────────────────

    /** @test */
    public function every_reconciliation_verdict_paints_its_card(): void
    {
        $groupA = $this->group(100, 'Tuesday Group', 'tues@example.test', [
            $this->contact('Alex', '0117 000 0001'),
            $this->contact('Sam', ''),          // name only
            $this->contact('', '0117 000 0003'), // phone only
            $this->contact('Jo', '0117 000 0004'), // 4th — trimmed by the 3-contact cap
        ]);

        $this->meetingRepository->method('findAll')->willReturn([
            $this->meeting(1, ['day' => 2, 'location' => $this->location('Church Hall', '1 High St, Bristol'), 'groupId' => 100]),
            $this->meeting(2, ['day' => 2, 'time' => '', 'online' => true, 'onlineLink' => 'https://zoom.example', 'groupId' => 100]),
            $this->meeting(3, ['day' => 3, 'endTime' => '', 'online' => true, 'onlineLink' => '']),
            $this->meeting(4, ['day' => 4, 'location' => null, 'groupId' => 200]),
            $this->meeting(5, ['day' => 5, 'groupId' => 300]),
            $this->meeting(6, ['day' => 0, 'groupId' => 0]),
        ]);

        $this->groupRepository->method('findById')->willReturnMap([
            [100, $groupA],
            [200, $this->group(200, 'No-Email Group')],
            [300, $this->group(300, 'Emailed Group', 'grp@example.test')],
        ]);

        $html = $this->capture(fn () => $this->dashboard($this->fullReconciler())->renderDashboardWidget());

        // Confident match: plain AAGBDB badge and an Open status pill.
        $this->assertStringContainsString('recon-matched', $html);
        $this->assertStringContainsString('status-open', $html);
        // Partial match carries its caveat.
        $this->assertStringContainsString('recon-partial', $html);
        $this->assertStringContainsString('End time mismatch', $html);
        // Closed national match and its closed pill.
        $this->assertStringContainsString('recon-closed', $html);
        $this->assertStringContainsString('status-closed', $html);
        // Possible and local-only.
        $this->assertStringContainsString('recon-possible', $html);
        $this->assertStringContainsString('recon-missing', $html);
        $this->assertStringContainsString('No national candidate', $html);
        // Local data made it in.
        $this->assertStringContainsString('Church Hall', $html);
        $this->assertStringContainsString('tues@example.test', $html);
        $this->assertStringContainsString('Alex', $html);
        // The 4th contact is past the 3-contact cap.
        $this->assertStringNotContainsString('0117 000 0004', $html);
        // Online meeting with a link, and one without.
        $this->assertStringContainsString('Online Meeting', $html);
        $this->assertStringContainsString('meeting-online-label', $html);
    }

    // ── graceful paths ───────────────────────────────────────────────

    /** @test */
    public function an_empty_site_says_no_meetings_found(): void
    {
        $this->meetingRepository->method('findAll')->willReturn([]);

        $this->assertStringContainsString(
            'No meetings found',
            $this->capture(fn () => $this->dashboard()->renderDashboardWidget())
        );
    }

    /** @test */
    public function without_a_reconciler_the_cards_still_render_with_dashes(): void
    {
        // Concordance absent: every national field degrades to an em dash
        // rather than the widget failing.
        $this->meetingRepository->method('findAll')->willReturn([
            $this->meeting(1, ['groupId' => null, 'contacts' => []]),
        ]);

        $html = $this->capture(fn () => $this->dashboard(null)->renderDashboardWidget());

        $this->assertStringContainsString('meeting-card', $html);
        $this->assertStringContainsString('National Listing', $html);
        $this->assertStringNotContainsString('recon-matched', $html);
    }

    /** @test */
    public function a_reconciler_that_throws_is_swallowed(): void
    {
        $reconciler = $this->createMock(MeetingReconciler::class);
        $reconciler->method('reconcile')->willThrowException(new \RuntimeException('API down'));

        $this->meetingRepository->method('findAll')->willReturn([$this->meeting(1)]);

        $html = $this->capture(fn () => $this->dashboard($reconciler)->renderDashboardWidget());

        // Reconciliation failed, but the dashboard still drew the card.
        $this->assertStringContainsString('meeting-card', $html);
    }

    /** @test */
    public function the_widget_is_registered(): void
    {
        $this->dashboard()->registerDashboardWidget();

        $this->assertArrayHasKey('groups_meetings_dashboard', WpState::$widgets);
    }

    /** @test */
    public function styles_and_scripts_only_load_on_the_dashboard(): void
    {
        $dashboard = $this->dashboard();

        $this->setScreen('dashboard', 'dashboard');
        $this->assertStringContainsString('<style>', $this->capture(fn () => $dashboard->addDashboardStyles()));
        $this->assertStringContainsString('<script>', $this->capture(fn () => $dashboard->addDashboardScripts()));

        $this->setScreen('edit-post', 'edit', 'post');
        $this->assertSame('', $this->capture(fn () => $dashboard->addDashboardStyles()));
        $this->assertSame('', $this->capture(fn () => $dashboard->addDashboardScripts()));
    }
}
