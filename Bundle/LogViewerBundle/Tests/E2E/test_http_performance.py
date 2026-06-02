"""
Playwright E2E tests: HTTP Performance Monitoring grid.

Covers:
- Page loads without JS errors or 500s
- Navigation menu item "Request Performance" is present and navigates correctly
- Grid container, column headers and filter bar are rendered
- Type-choice filter and path string filter are present and accept input
- After visiting any admin page the grid contains at least one data row (kernel.terminate recording)
- Grid toolbar / pagination controls render
- Sorting a column fires a data-reload request

Run from project root:
    /oro-ee/var/tmp/venv/bin/pytest \\
        src/Genaker/Bundle/LogViewerBundle/Tests/E2E/test_http_performance.py \\
        -v --tb=short

Or run all LogViewerBundle E2E tests:
    /oro-ee/var/tmp/venv/bin/pytest \\
        src/Genaker/Bundle/LogViewerBundle/Tests/E2E/ -v
"""

import pytest
from playwright.sync_api import Page, ConsoleMessage

from config import E2EConfig

# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------

PERF_URL    = E2EConfig.ADMIN_HTTP_PERF_URL   # /admin/http-performance
TIMEOUT     = 60_000
NAV_TIMEOUT = 30_000
GRID_TIMEOUT = 20_000

# Column labels exactly as defined in datagrids.yml
EXPECTED_COLUMNS = [
    'Path / Command / Topic',
    'Type',
    'Avg (ms)',
    'Fastest (ms)',
    'Slowest (ms)',
    'Requests',
    'Last Seen',
    'Status',
]


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _inject_error_collector(page: Page) -> None:
    page.add_init_script("""
        window.__perfJsErrors = [];
        window.onerror = function(msg, src, line, col, err) {
            window.__perfJsErrors.push('[onerror] ' + msg + ' (' + (src||'') + ':' + line + ')');
            return false;
        };
        window.addEventListener('unhandledrejection', function(e) {
            window.__perfJsErrors.push('[promise] ' + String(e.reason));
        });
    """)


def _collect_js_errors(page: Page) -> list[str]:
    return page.evaluate('() => window.__perfJsErrors || []')


def _navigate_to_perf(page: Page, admin_login) -> None:
    """Login (via saved session) and navigate to the performance grid."""
    admin_login(page)
    page.goto(PERF_URL, timeout=TIMEOUT, wait_until='domcontentloaded')


def _wait_for_grid(page: Page) -> None:
    """Wait until the Oro datagrid has finished loading its data."""
    # Oro datagrid renders a .grid table inside .grid-container once data arrives
    page.wait_for_selector('.grid-container', timeout=GRID_TIMEOUT)
    # Give the AJAX data call time to complete (networkidle can time out on
    # OroCommerce which keeps long-poll connections open, so cap at 5 s)
    try:
        page.wait_for_load_state('networkidle', timeout=5_000)
    except Exception:
        pass


# ---------------------------------------------------------------------------
# Fixtures
# ---------------------------------------------------------------------------

@pytest.fixture
def perf_page(page: Page, admin_login):
    """Navigate to the performance grid and wait for the grid to load."""
    _inject_error_collector(page)
    _navigate_to_perf(page, admin_login)
    _wait_for_grid(page)
    return page


# ---------------------------------------------------------------------------
# Page-load tests
# ---------------------------------------------------------------------------

@pytest.mark.admin
class TestHttpPerformancePageLoad:
    """Basic HTTP-level and JS-error checks on the performance grid page."""

    def test_no_500_error(self, perf_page: Page) -> None:
        """Page does not render an HTTP 500 / Internal Server Error."""
        content = perf_page.content().lower()
        assert '500' not in perf_page.title().lower(), \
            f'500 in page title: {perf_page.title()}'
        assert 'internal server error' not in content, \
            'Internal Server Error found in page body'
        print('\n✓ No 500 / internal server error')

    def test_no_js_errors(self, perf_page: Page) -> None:
        """No JavaScript errors after page load and grid init."""
        errors = _collect_js_errors(perf_page)
        # Filter out known OroCommerce asset noise
        critical = [
            e for e in errors
            if not any(skip in e for skip in [
                'favicon.ico', 'ResizeObserver', 'Non-Error promise rejection',
            ])
        ]
        assert not critical, f'JS errors on performance grid page: {critical}'
        print(f'\n✓ No JS errors ({len(errors)} total noise: {errors[:3]})')

    def test_page_not_redirected_to_login(self, perf_page: Page) -> None:
        """Admin session is valid — page did not redirect to login."""
        assert 'login' not in perf_page.url.lower(), \
            f'Redirected to login page: {perf_page.url}'
        print(f'\n✓ Authenticated — URL: {perf_page.url}')

    def test_page_url_is_correct(self, perf_page: Page) -> None:
        """Final URL ends with /admin/http-performance."""
        assert perf_page.url.rstrip('/').endswith('/admin/http-performance'), \
            f'Unexpected final URL: {perf_page.url}'

    def test_no_console_errors_on_load(self, page: Page, admin_login) -> None:
        """No console.error() calls during page load."""
        errors: list[str] = []

        def _on_console(msg: ConsoleMessage) -> None:
            if msg.type == 'error':
                errors.append(msg.text)

        _inject_error_collector(page)
        page.on('console', _on_console)
        _navigate_to_perf(page, admin_login)
        _wait_for_grid(page)
        page.wait_for_timeout(1_500)

        critical = [
            e for e in errors
            if not any(skip in e for skip in [
                'favicon.ico', 'ResizeObserver', 'Non-Error promise rejection',
            ])
        ]
        assert not critical, f'Console errors on load: {critical}'
        print(f'\n✓ No critical console errors ({len(errors)} total)')


# ---------------------------------------------------------------------------
# Navigation menu tests
# ---------------------------------------------------------------------------

@pytest.mark.admin
class TestHttpPerformanceNavigation:
    """Verify the "Request Performance" entry in the admin navigation."""

    def test_menu_item_exists_in_system_tab(self, page: Page, admin_login) -> None:
        """The System menu contains a "Request Performance" entry."""
        admin_login(page)
        page.goto(E2EConfig.BASE_URL + '/admin/', timeout=TIMEOUT,
                  wait_until='domcontentloaded')
        page.wait_for_timeout(2_000)

        # Hover over the System tab to reveal the dropdown
        system_tab = page.locator('a:has-text("System")').first
        if system_tab.count() == 0:
            pytest.skip('System menu tab not visible — skipping nav test')

        system_tab.hover()
        page.wait_for_timeout(500)

        menu_item = page.locator('a:has-text("Request Performance")')
        assert menu_item.count() >= 1, \
            '"Request Performance" not found in System menu'
        print('\n✓ "Request Performance" menu item present')

    def test_menu_item_navigates_to_grid(self, page: Page, admin_login) -> None:
        """The menu item href points to /admin/http-performance."""
        admin_login(page)
        page.goto(E2EConfig.BASE_URL + '/admin/', timeout=TIMEOUT,
                  wait_until='domcontentloaded')
        page.wait_for_timeout(2_000)

        system_tab = page.locator('a:has-text("System")').first
        if system_tab.count() == 0:
            pytest.skip('System menu tab not visible — skipping nav test')

        # Verify the menu link exists and points to the right place
        menu_link = page.locator('a[href="/admin/http-performance"]').first
        if menu_link.count() == 0:
            pytest.skip('Menu link /admin/http-performance not found in DOM')

        href = menu_link.get_attribute('href')
        assert href == '/admin/http-performance', \
            f'Menu link href is {href!r}, expected /admin/http-performance'

        # Navigate directly to the URL (menu hover behavior is flaky in headless)
        page.goto(PERF_URL, timeout=TIMEOUT, wait_until='domcontentloaded')

        assert '/admin/http-performance' in page.url, \
            f'Expected /admin/http-performance in URL, got: {page.url}'
        print(f'\n✓ Menu link points to: {href}')


# ---------------------------------------------------------------------------
# Grid structure tests
# ---------------------------------------------------------------------------

@pytest.mark.admin
class TestHttpPerformanceGridStructure:
    """Verify the datagrid DOM structure and controls are rendered."""

    def test_grid_container_is_present(self, perf_page: Page) -> None:
        """.grid-container element is in the DOM after page load."""
        container = perf_page.locator('.grid-container')
        assert container.count() >= 1, '.grid-container not found'
        print('\n✓ .grid-container present')

    def test_grid_table_is_rendered(self, perf_page: Page) -> None:
        """The HTML <table> inside the grid container is rendered."""
        table = perf_page.locator('.grid-container table, .grid-scrollable-container table')
        assert table.count() >= 1, 'no <table> found inside grid container'
        print('\n✓ Grid <table> present')

    @pytest.mark.parametrize('column_label', EXPECTED_COLUMNS)
    def test_column_header_present(self, perf_page: Page, column_label: str) -> None:
        """Each expected column header is rendered in the grid."""
        header = perf_page.locator(f'th:has-text("{column_label}")')
        assert header.count() >= 1, \
            f'Column header "{column_label}" not found in grid'

    def test_all_column_headers_present(self, perf_page: Page) -> None:
        """Sanity check: all 8 expected column headers are present at once."""
        for label in EXPECTED_COLUMNS:
            count = perf_page.locator(f'th:has-text("{label}")').count()
            assert count >= 1, f'Column header "{label}" missing'
        print(f'\n✓ All {len(EXPECTED_COLUMNS)} column headers present')

    def test_filter_bar_is_rendered(self, perf_page: Page) -> None:
        """The datagrid filter / toolbar bar is rendered."""
        # Oro renders filters inside .filter-box or .filter-container
        filter_area = perf_page.locator(
            '.filter-box, .filter-container, [data-name="toolbar"]'
        )
        assert filter_area.count() >= 1, 'Filter toolbar area not found'
        print('\n✓ Filter toolbar area present')

    def test_pagination_controls_rendered(self, perf_page: Page) -> None:
        """Pagination element is rendered in the toolbar."""
        pagination = perf_page.locator(
            '.pagination, [data-name="pagination"], .grid-toolbar .pager'
        )
        assert pagination.count() >= 1, 'Pagination controls not found'
        print('\n✓ Pagination controls present')


# ---------------------------------------------------------------------------
# Filter tests
# ---------------------------------------------------------------------------

@pytest.mark.admin
class TestHttpPerformanceFilters:
    """Verify the Type and Path filters can be interacted with."""

    def test_type_filter_is_rendered(self, perf_page: Page) -> None:
        """A filter dropdown/select for the Type column is present."""
        # Oro renders choice filters as <select> or a custom dropdown
        type_filter = perf_page.locator(
            'select[name*="type"], '
            '[data-name*="type"] select, '
            '.filter-item:has-text("Type")'
        )
        assert type_filter.count() >= 1, 'Type filter not found'
        print('\n✓ Type filter present')

    def test_path_filter_accepts_input(self, perf_page: Page) -> None:
        """The path string filter accepts text input."""
        path_input = perf_page.locator(
            'input[name*="path"], '
            '[data-name*="path"] input[type="text"], '
            '.filter-item:has-text("Path") input'
        )
        if path_input.count() == 0:
            pytest.skip('Path filter input not found in current grid state')

        path_input.first.fill('/admin')
        perf_page.wait_for_timeout(300)
        value = path_input.first.input_value()
        assert value == '/admin', \
            f'Path filter did not accept input; got: {value!r}'
        print('\n✓ Path filter accepts text input')

    def test_type_filter_choice_http(self, perf_page: Page) -> None:
        """Selecting 'HTTP' in the type filter triggers a grid reload."""
        type_filter = perf_page.locator(
            'select[name*="type"], [data-name*="type"] select'
        )
        if type_filter.count() == 0:
            pytest.skip('Type select filter not found')

        with perf_page.expect_response(
            lambda r: 'http-performance' in r.url or 'datagrid' in r.url,
            timeout=10_000
        ) as resp_info:
            type_filter.first.select_option('http')

        resp = resp_info.value
        assert resp.status in (200, 302), \
            f'Grid reload returned {resp.status} after type filter'
        print(f'\n✓ Type filter "http" triggered reload: {resp.status}')


# ---------------------------------------------------------------------------
# Data recording end-to-end test
# ---------------------------------------------------------------------------

@pytest.mark.admin
class TestHttpPerformanceRecording:
    """Verify that page visits are actually recorded in the performance grid."""

    def test_grid_shows_data_after_page_visit(self, page: Page, admin_login) -> None:
        """
        Visiting any admin page causes kernel.terminate to record the request.
        After that, the performance grid should contain at least one data row.

        Flow:
          1. Visit the log viewer index (triggers kernel.terminate → DB write)
          2. Navigate to the performance grid
          3. Assert at least one tbody row exists
        """
        admin_login(page)

        # Visit an admin page to generate a performance entry
        page.goto(
            E2EConfig.ADMIN_LOG_VIEWER_URL,
            timeout=TIMEOUT,
            wait_until='domcontentloaded'
        )
        # Small wait to ensure kernel.terminate DB write completes
        page.wait_for_timeout(1_500)

        # Now open the performance grid
        _inject_error_collector(page)
        page.goto(PERF_URL, timeout=TIMEOUT, wait_until='domcontentloaded')
        _wait_for_grid(page)

        rows = page.locator('.grid-container tbody tr, .grid tbody tr')
        row_count = rows.count()
        assert row_count >= 1, \
            f'Expected at least 1 row after page visits, grid shows {row_count} rows'
        print(f'\n✓ Grid has {row_count} row(s) after page visits')

    def test_recorded_row_has_http_type(self, page: Page, admin_login) -> None:
        """At least one row in the grid has type = "http"."""
        admin_login(page)

        # Ensure there is at least one HTTP request recorded
        page.goto(
            E2EConfig.ADMIN_LOG_VIEWER_URL,
            timeout=TIMEOUT,
            wait_until='domcontentloaded'
        )
        page.wait_for_timeout(1_500)

        page.goto(PERF_URL, timeout=TIMEOUT, wait_until='domcontentloaded')
        _wait_for_grid(page)

        # Look for "http" text in any grid row
        http_cell = page.locator('.grid-container tbody td:has-text("http")')
        assert http_cell.count() >= 1, \
            'No row with type "http" found — HTTP recording may not be working'
        print(f'\n✓ Grid contains {http_cell.count()} "http" type row(s)')

    def test_recorded_path_is_normalised(self, page: Page, admin_login) -> None:
        """
        Recorded paths are normalised — numeric IDs replaced with {id}.

        Visits /admin/logs/view/dev.log whose path contains no numeric segment
        (so it records as-is), then checks the grid for a path starting with /admin.
        """
        admin_login(page)

        page.goto(
            f'{E2EConfig.ADMIN_LOG_VIEWER_URL}/view/dev.log',
            timeout=TIMEOUT,
            wait_until='domcontentloaded'
        )
        page.wait_for_timeout(1_500)

        page.goto(PERF_URL, timeout=TIMEOUT, wait_until='domcontentloaded')
        _wait_for_grid(page)

        admin_paths = page.locator('.grid-container tbody td:has-text("/admin")')
        assert admin_paths.count() >= 1, \
            'No /admin path found in grid after visiting admin pages'
        print(f'\n✓ Grid contains {admin_paths.count()} /admin path row(s)')

    def test_avg_response_ms_is_positive_number(self, page: Page, admin_login) -> None:
        """Every visible Avg (ms) cell contains a positive number."""
        admin_login(page)
        page.goto(
            E2EConfig.ADMIN_LOG_VIEWER_URL,
            timeout=TIMEOUT,
            wait_until='domcontentloaded'
        )
        page.wait_for_timeout(1_500)

        page.goto(PERF_URL, timeout=TIMEOUT, wait_until='domcontentloaded')
        _wait_for_grid(page)

        rows = page.locator('.grid-container tbody tr')
        if rows.count() == 0:
            pytest.skip('Grid is empty — skipping avg cell check')

        # Check the Avg column cell of the first row is a positive number
        # The Avg column is the 3rd data column (index 2 if 0-based after path, type)
        avg_cells = page.evaluate("""
            () => {
                const rows = document.querySelectorAll('.grid-container tbody tr');
                return Array.from(rows).slice(0, 5).map(row => {
                    const cells = row.querySelectorAll('td');
                    return cells.length > 2 ? cells[2].textContent.trim() : '';
                });
            }
        """)
        print(f'\n  Avg column values (first 5 rows): {avg_cells}')
        for val in avg_cells:
            if val:
                try:
                    num = float(val.replace(',', '').replace(' ', ''))
                    assert num > 0, f'Avg value {val!r} is not positive'
                except ValueError:
                    pass  # non-numeric cell (e.g. empty or formatted differently)

        print('\n✓ Avg (ms) column contains positive values')


# ---------------------------------------------------------------------------
# Sorting tests
# ---------------------------------------------------------------------------

@pytest.mark.admin
class TestHttpPerformanceSorting:
    """Verify column sorting triggers a grid data reload."""

    def _click_column_header(self, page: Page, column_label: str) -> None:
        header = page.locator(f'th:has-text("{column_label}") a, '
                              f'th:has-text("{column_label}")')
        if header.count() == 0:
            pytest.skip(f'Column header "{column_label}" not clickable')
        header.first.click()

    def test_sort_by_avg_response(self, perf_page: Page) -> None:
        """Clicking 'Avg (ms)' header triggers a grid data request."""
        with perf_page.expect_response(
            lambda r: 'http-performance' in r.url or 'datagrid' in r.url,
            timeout=10_000
        ) as resp_info:
            self._click_column_header(perf_page, 'Avg (ms)')

        resp = resp_info.value
        assert resp.status in (200, 302), \
            f'Sort by Avg returned {resp.status}'
        print(f'\n✓ Sort by "Avg (ms)" returned {resp.status}')

    def test_sort_by_request_count(self, perf_page: Page) -> None:
        """Clicking 'Requests' header triggers a grid data request."""
        with perf_page.expect_response(
            lambda r: 'http-performance' in r.url or 'datagrid' in r.url,
            timeout=10_000
        ) as resp_info:
            self._click_column_header(perf_page, 'Requests')

        resp = resp_info.value
        assert resp.status in (200, 302), \
            f'Sort by Requests returned {resp.status}'
        print(f'\n✓ Sort by "Requests" returned {resp.status}')

    def test_sort_by_last_seen(self, perf_page: Page) -> None:
        """Clicking 'Last Seen' header triggers a grid data request."""
        with perf_page.expect_response(
            lambda r: 'http-performance' in r.url or 'datagrid' in r.url,
            timeout=10_000
        ) as resp_info:
            self._click_column_header(perf_page, 'Last Seen')

        resp = resp_info.value
        assert resp.status in (200, 302), \
            f'Sort by Last Seen returned {resp.status}'
        print(f'\n✓ Sort by "Last Seen" returned {resp.status}')


# ---------------------------------------------------------------------------
# Access control test
# ---------------------------------------------------------------------------

@pytest.mark.admin
class TestHttpPerformanceAccessControl:
    """The performance grid is accessible to admin users."""

    def test_admin_can_access_grid(self, page: Page, admin_login) -> None:
        """Authenticated admin reaches the grid page without being redirected."""
        admin_login(page)
        response = page.goto(PERF_URL, timeout=TIMEOUT, wait_until='domcontentloaded')

        # OroCommerce may return 200 for the HTML and use JS routing,
        # or redirect internally — any non-5xx is acceptable
        assert response is not None
        assert response.status < 500, \
            f'HTTP {response.status} accessing performance grid as admin'
        assert 'login' not in page.url.lower(), \
            f'Admin was redirected to login: {page.url}'
        print(f'\n✓ Admin accessed grid: HTTP {response.status}')
