<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Managers;

use Amber\Managers\PositionShortcodeRenderer;
use Amber\Tests\AmberTestCase;
use Amber\Tests\WpState;
use DateTime;
use Unity\Core\Interfaces\Configuration;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionView;
use Unity\Positions\Interfaces\PositionViewFactory;

/**
 * Tests for the public position shortcodes.
 *
 * These are the tags an intergroup drops into a page to show who holds which
 * service position and when they rotate. Each callback wraps its work in a
 * try/catch that swallows the error into a bland "Error building…" string, so
 * the two things worth pinning are: the visible copy each rotation state
 * produces (Vacant, Overdue, Rotates in N Months, tenure), and that a missing
 * post id lands in the guarded fallback rather than a white screen.
 *
 * @covers \Amber\Managers\PositionShortcodeRenderer
 */
class PositionShortcodeRendererTest extends AmberTestCase
{
    private PositionShortcodeRenderer $renderer;

    /** @var PositionViewFactory&\PHPUnit\Framework\MockObject\MockObject */
    private $viewFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->createMock(Configuration::class);
        $config->method('getConfig')->willReturn(['POST_TYPE' => 'intergroup-position', 'SUMMARY' => 'summary']);

        $this->viewFactory = $this->createMock(PositionViewFactory::class);

        $this->renderer = new PositionShortcodeRenderer($config, $this->viewFactory);
    }

    private function atCurrentPost(int $id = 7): void
    {
        WpState::$options['__current_post_id'] = $id;
    }

    private function position(array $overrides = []): Position
    {
        $defaults = [
            'getMinimumSobriety' => 24,
            'getTermYears'       => 3,
            'getLink'            => 'https://example.test/positions/treasurer',
            'getLongName'        => 'Treasurer',
        ];

        $position = $this->createMock(Position::class);
        foreach (array_merge($defaults, $overrides) as $method => $value) {
            $position->method($method)->willReturn($value);
        }

        return $position;
    }

    private function view(array $overrides = [], ?Position $position = null): PositionView
    {
        $defaults = [
            'isArchivist'            => false,
            'isVacant'               => false,
            'getRotationDate'        => new DateTime('2030-01-01'),
            'getMonthsUntilRotation' => 3,
            'getTitle'               => 'Treasurer',
            'getPositionEmail'       => 'treasurer@example.test',
            'getDescription'         => 'Treasurer',
            'getPublicDisplayName'   => 'Anonymous Alex',
        ];

        $view = $this->createMock(PositionView::class);
        foreach (array_merge($defaults, $overrides) as $method => $value) {
            $view->method($method)->willReturn($value);
        }
        $view->method('getPosition')->willReturn($position ?? $this->position());

        return $view;
    }

    // ── position_state ───────────────────────────────────────────────

    /** @test */
    public function position_state_without_a_current_post_falls_back_gracefully(): void
    {
        WpState::$options['__current_post_id'] = 0;

        $this->assertStringContainsString('Error building position state', $this->renderer->renderPositionState());
    }

    /** @test */
    public function position_state_for_a_vacant_post_says_vacant(): void
    {
        $this->atCurrentPost();
        $this->viewFactory->method('createFrom')->willReturn($this->view(['isVacant' => true]));

        $html = $this->renderer->renderPositionState();

        $this->assertStringContainsString('Vacant!', $html);
        $this->assertStringContainsString('Email Service Officer', $html);
    }

    /** @test */
    public function position_state_for_an_archivist_shows_no_rotation_heading(): void
    {
        $this->atCurrentPost();
        $this->viewFactory->method('createFrom')->willReturn($this->view(['isArchivist' => true]));

        $html = $this->renderer->renderPositionState();

        // Archivist tenure is permanent, so the heading is intentionally blank.
        $this->assertStringContainsString('<h1></h1>', $html);
    }

    /** @test */
    public function position_state_without_a_rotation_date_flags_it(): void
    {
        $this->atCurrentPost();
        $this->viewFactory->method('createFrom')->willReturn($this->view(['getRotationDate' => null]));

        $this->assertStringContainsString('No Rotation Date!', $this->renderer->renderPositionState());
    }

    /**
     * @test
     * @dataProvider rotationStatusProvider
     */
    public function position_state_describes_the_rotation_status(?int $months, string $expected): void
    {
        $this->atCurrentPost();
        $this->viewFactory->method('createFrom')->willReturn(
            $this->view(['getMonthsUntilRotation' => $months])
        );

        $this->assertStringContainsString($expected, $this->renderer->renderPositionState());
    }

    /** @return array<string, array{0: int|null, 1: string}> */
    public static function rotationStatusProvider(): array
    {
        return [
            'overdue'      => [-2, 'Rotation Overdue!'],
            'due now'      => [0, 'Rotation Due Now'],
            'next month'   => [1, 'Rotation Next Month'],
            'within window' => [3, 'Rotates in 3 Months'],
            'unknown'      => [null, 'Status Unknown'],
        ];
    }

    /** @test */
    public function position_state_far_from_rotation_shows_an_empty_status(): void
    {
        // Beyond the warning window there is nothing to flag, so the heading
        // collapses to empty.
        $this->atCurrentPost();
        $this->viewFactory->method('createFrom')->willReturn($this->view(['getMonthsUntilRotation' => 24]));

        $this->assertStringContainsString('<h1></h1>', $this->renderer->renderPositionState());
    }

    // ── position_header ──────────────────────────────────────────────

    /** @test */
    public function position_header_renders_title_sobriety_and_term(): void
    {
        $this->atCurrentPost();
        $this->viewFactory->method('createFrom')->willReturn($this->view());

        $html = $this->renderer->renderPositionHeader();

        $this->assertStringContainsString('Treasurer', $html);
        $this->assertStringContainsString('Sobriety 2 Years', $html);
        $this->assertStringContainsString('Term 3 Years', $html);
    }

    /** @test */
    public function position_header_renders_sobriety_in_months_when_not_a_whole_year(): void
    {
        $this->atCurrentPost();
        $this->viewFactory->method('createFrom')->willReturn(
            $this->view([], $this->position(['getMinimumSobriety' => 18]))
        );

        $this->assertStringContainsString('Sobriety 18 Months', $this->renderer->renderPositionHeader());
    }

    /** @test */
    public function position_header_uses_the_singular_year_for_a_single_year_of_sobriety(): void
    {
        $this->atCurrentPost();
        $this->viewFactory->method('createFrom')->willReturn(
            $this->view([], $this->position(['getMinimumSobriety' => 12, 'getTermYears' => 1]))
        );

        $html = $this->renderer->renderPositionHeader();

        $this->assertStringContainsString('Sobriety 1 Year', $html);
        $this->assertStringContainsString('Term 1 Year', $html);
    }

    /** @test */
    public function position_header_shows_tenure_for_an_archivist(): void
    {
        $this->atCurrentPost();
        $this->viewFactory->method('createFrom')->willReturn($this->view(['isArchivist' => true]));

        $this->assertStringContainsString('Term Tenure', $this->renderer->renderPositionHeader());
    }

    /** @test */
    public function position_header_labels_the_email_officer_when_the_title_says_officer(): void
    {
        $this->atCurrentPost();
        $this->viewFactory->method('createFrom')->willReturn($this->view(['getTitle' => 'Public Information Officer']));

        $this->assertStringContainsString('Email Officer', $this->renderer->renderPositionHeader());
    }

    /** @test */
    public function position_header_hides_the_email_link_for_a_vacant_post(): void
    {
        $this->atCurrentPost();
        $this->viewFactory->method('createFrom')->willReturn($this->view(['isVacant' => true]));

        $this->assertStringNotContainsString('pseudo_link', $this->renderer->renderPositionHeader());
    }

    // ── directory_list ───────────────────────────────────────────────

    /** @test */
    public function the_directory_table_renders_a_row_per_position(): void
    {
        $this->viewFactory->method('createAll')->willReturn([
            $this->view(['getDescription' => 'Treasurer']),
            $this->view(['isVacant' => true, 'getDescription' => 'Secretary']),
            $this->view(['isArchivist' => true, 'getDescription' => 'Archivist']),
            $this->view(['getRotationDate' => null, 'getDescription' => 'Chair']),
        ]);

        $html = $this->renderer->renderDirectoryTable();

        $this->assertStringContainsString('id="service_positions"', $html);
        $this->assertStringContainsString('Treasurer', $html);
        $this->assertStringContainsString('Position Vacant', $html);
        $this->assertStringContainsString('No Rotation Date!', $html);
        $this->assertSame(4, substr_count($html, '<tr>'));
    }

    // ── position_summary ─────────────────────────────────────────────

    /** @test */
    public function the_position_summary_wraps_the_summary_field(): void
    {
        $this->atCurrentPost();
        $this->setField(7, 'summary', '<p>Keeps the books.</p>');

        $html = $this->renderer->renderPositionSummary();

        $this->assertStringContainsString('Keeps the books.', $html);
    }

    /** @test */
    public function the_position_summary_falls_back_without_a_current_post(): void
    {
        WpState::$options['__current_post_id'] = 0;

        $this->assertStringContainsString('Error loading position summary', $this->renderer->renderPositionSummary());
    }

    // ── vacant_positions ─────────────────────────────────────────────

    /** @test */
    public function vacant_positions_lists_only_the_vacant_ones(): void
    {
        $this->viewFactory->method('createAll')->willReturn([
            $this->view(['isVacant' => false, 'getDescription' => 'Treasurer']),
            $this->view(['isVacant' => true, 'getDescription' => 'Secretary']),
        ]);

        $html = $this->renderer->renderVacantPositions();

        $this->assertStringContainsString('Secretary', $html);
        $this->assertStringNotContainsString('Treasurer', $html);
    }

    /** @test */
    public function a_vacant_position_without_a_description_falls_back_to_its_long_name(): void
    {
        $this->viewFactory->method('createAll')->willReturn([
            $this->view(['isVacant' => true, 'getDescription' => ''], $this->position(['getLongName' => 'General Service Rep'])),
        ]);

        $this->assertStringContainsString('General Service Rep', $this->renderer->renderVacantPositions());
    }

    /** @test */
    public function vacant_positions_says_so_when_there_are_none(): void
    {
        $this->viewFactory->method('createAll')->willReturn([
            $this->view(['isVacant' => false]),
        ]);

        $this->assertStringContainsString('no vacant positions', $this->renderer->renderVacantPositions());
    }

    // ── guarded failure paths ────────────────────────────────────────

    /** @test */
    public function the_header_falls_back_when_the_view_cannot_be_built(): void
    {
        $this->atCurrentPost();
        $this->viewFactory->method('createFrom')->willThrowException(new \RuntimeException('boom'));

        $this->assertStringContainsString('Error building position header', $this->renderer->renderPositionHeader());
    }

    /** @test */
    public function the_directory_table_falls_back_on_error(): void
    {
        $this->viewFactory->method('createAll')->willThrowException(new \RuntimeException('boom'));

        $this->assertStringContainsString('Error generating directory list', $this->renderer->renderDirectoryTable());
    }

    /** @test */
    public function the_vacant_list_falls_back_on_error(): void
    {
        $this->viewFactory->method('createAll')->willThrowException(new \RuntimeException('boom'));

        $this->assertStringContainsString('Error building vacant positions list', $this->renderer->renderVacantPositions());
    }
}
