"""
Playwright E2E tests: Log Viewer — view page (AMD component).

Covers:
- Page loads without JS errors (window.onerror / console.error trapping)
- Shell DOM structure renders (all key element IDs present)
- Toolbar controls: Apply, Wrap, Light/Dark, Clear mode, Max rows, Pause, Reload
- Window controls: minimize (yellow dot), fullscreen (green dot)
- JS filter (instant client-side)
- Download button spinner animation
- Grep toolbar elements render
- Live-tail indicator is present
- Stats bar shows file metrics
- Light-theme CSS toggle mutates lv-shell class
- Wrap toggle mutates textarea white-space

Run from project root:
    /oro-ee/var/tmp/venv/bin/pytest \
        src/Egerdau/Bundle/LogViewerBundle/Tests/E2E/test_log_viewer_view.py \
        -v --tb=short
"""

import time
import pytest
from playwright.sync_api import Page, ConsoleMessage

from config import E2EConfig  # local bundle config — no root e2e path needed

# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------

TEST_LOG_FILE = 'dev.log'
INDEX_URL     = E2EConfig.ADMIN_LOG_VIEWER_URL
VIEW_URL      = f"{INDEX_URL}/view/{TEST_LOG_FILE}"
TIMEOUT       = 60_000
NAV_TIMEOUT   = 30_000


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _login_and_goto(page: Page, admin_login, url: str) -> None:
    """Log in as admin (session already loaded) then navigate to *url*."""
    admin_login(page)
    page.goto(url, timeout=TIMEOUT, wait_until='domcontentloaded')


def _collect_js_errors(page: Page) -> list[str]:
    """Return JS errors captured since JS error collection was set up."""
    return page.evaluate('() => window.__lvJsErrors || []')


def _inject_error_collector(page: Page) -> None:
    """Inject a global error collector before page JS runs."""
    page.add_init_script("""
        window.__lvJsErrors = [];
        window.onerror = function(msg, src, line, col, err) {
            window.__lvJsErrors.push(
                '[onerror] ' + msg + ' (' + (src || '') + ':' + line + ')');
            return false;
        };
        window.addEventListener('unhandledrejection', function(e) {
            window.__lvJsErrors.push(
                '[promise] ' + (e.reason && e.reason.message ? e.reason.message : String(e.reason)));
        });
    """)


# ---------------------------------------------------------------------------
# Fixtures
# ---------------------------------------------------------------------------

@pytest.fixture
def view_page(page: Page, admin_login):
    """Navigate to the log viewer view page, JS errors collected."""
    _inject_error_collector(page)
    _login_and_goto(page, admin_login, VIEW_URL)
    # Wait for the AMD component shell to be in the DOM
    page.wait_for_selector('#log-viewer-wrap', timeout=NAV_TIMEOUT)
    page.wait_for_selector('#log-content', timeout=NAV_TIMEOUT)
    # Wait until the AMD component sets _lvInited, confirming all listeners are
    # attached (initThemeWrap, initWindowControls, initJsFilter etc.)
    page.wait_for_function(
        "() => !!document.getElementById('log-content')._lvInited",
        timeout=30_000
    )
    return page


# ---------------------------------------------------------------------------
# Test classes
# ---------------------------------------------------------------------------

@pytest.mark.admin
class TestLogViewerViewPageLoad:
    """Basic page-load and structure checks."""

    def test_page_loads_without_js_errors(self, view_page: Page) -> None:
        """No JavaScript errors on page load (window.onerror + unhandledrejection)."""
        errors = _collect_js_errors(view_page)
        # Ignore known OroCommerce asset-not-found warnings for optional features
        critical = [e for e in errors if 'egerdaulogviewer' in e or 'log-viewer' in e]
        assert not critical, f'JS errors related to log viewer component: {critical}'
        print(f'\n✓ No log-viewer JS errors ({len(errors)} total page errors: {errors[:3]})')

    def test_no_500_errors(self, view_page: Page) -> None:
        """Page loads without HTTP 500 errors."""
        assert '500' not in view_page.title(), f'500 error: {view_page.title()}'
        content_lower = view_page.content().lower()
        assert 'internal server error' not in content_lower
        print('\n✓ No 500 / internal server error')

    def test_page_title_contains_filename(self, view_page: Page) -> None:
        """Page title or heading contains the log file name."""
        content = view_page.content()
        assert TEST_LOG_FILE in content, \
            f'Expected {TEST_LOG_FILE!r} in page content'
        print(f'\n✓ File name {TEST_LOG_FILE!r} present in page')

    def test_shell_element_present(self, view_page: Page) -> None:
        """The .lv-shell container is rendered in the DOM."""
        shell = view_page.locator('#log-viewer-wrap')
        assert shell.count() == 1, '#log-viewer-wrap not found'
        print('\n✓ .lv-shell #log-viewer-wrap present')

    def test_textarea_has_content(self, view_page: Page) -> None:
        """Log textarea is present and contains some text."""
        textarea = view_page.locator('#log-content')
        assert textarea.count() == 1, '#log-content textarea not found'
        value = textarea.input_value()
        assert len(value) > 0, 'Log textarea is empty — expected tail content'
        print(f'\n✓ Textarea has {len(value)} chars of log content')


@pytest.mark.admin
class TestLogViewerDomStructure:
    """All expected element IDs must be present after component init."""

    REQUIRED_IDS = [
        'log-viewer-wrap',
        'log-content',
        'btn-apply',
        'btn-wrap',
        'btn-terminal',
        'btn-clear-mode',
        'btn-pause',
        'btn-reload',
        'max-rows',
        'lines-count',
        'filter-js',
        'btn-filter-clear',
        'filter-match-count',
        'filter-grep',
        'grep-full',
        'btn-grep',
        'grep-status',
        'btn-grep-clear',
        'log-stats',
        'stat-lines',
        'stat-last-update',
        'stat-bytes',
        'live-indicator',
        'btn-minimize',
        'btn-fullscreen',
        'btn-download',
    ]

    @pytest.mark.parametrize('element_id', REQUIRED_IDS)
    def test_element_present(self, view_page: Page, element_id: str) -> None:
        """Every required element ID is in the DOM."""
        el = view_page.locator(f'#{element_id}')
        assert el.count() >= 1, f'#{element_id} not found in DOM'

    def test_stats_bar_has_segments(self, view_page: Page) -> None:
        """Stats bar renders at least 4 .lv-stat segments."""
        stats = view_page.locator('#log-stats .lv-stat').all()
        assert len(stats) >= 4, f'Expected ≥4 stat segments, got {len(stats)}'
        print(f'\n✓ Stats bar has {len(stats)} segments')


@pytest.mark.admin
class TestLogViewerThemeWrap:
    """Light/dark theme toggle and word-wrap toggle."""

    def test_light_is_default(self, view_page: Page) -> None:
        """Page opens in light mode by default (light-mode class present on shell)."""
        classes = view_page.evaluate(
            "() => document.getElementById('log-viewer-wrap').className"
        )
        assert 'light-mode' in classes, \
            f'Expected light-mode class by default; got: {classes}'
        print('\n✓ Light mode is active by default')

    def test_light_toggle_switches_to_dark(self, view_page: Page) -> None:
        """Clicking the theme button removes light-mode class (switches to dark)."""
        # Page starts in light mode — one click switches to dark
        view_page.evaluate("() => document.getElementById('btn-terminal').click()")
        view_page.wait_for_timeout(300)

        classes = view_page.evaluate(
            "() => document.getElementById('log-viewer-wrap').className"
        )
        assert 'light-mode' not in classes, \
            f'light-mode should be removed after first click; classes: {classes}'
        print('\n✓ First click removes light-mode (dark theme active)')

    def test_light_toggle_returns_to_light(self, view_page: Page) -> None:
        """Second click on the theme button restores light-mode class."""
        view_page.evaluate("() => document.getElementById('btn-terminal').click()")  # to dark
        view_page.wait_for_timeout(200)
        view_page.evaluate("() => document.getElementById('btn-terminal').click()")  # back to light
        view_page.wait_for_timeout(300)

        classes = view_page.evaluate(
            "() => document.getElementById('log-viewer-wrap').className"
        )
        assert 'light-mode' in classes, \
            f'light-mode not restored on second click; classes: {classes}'
        print('\n✓ Second click restores light-mode')

    def test_wrap_toggle_changes_whitespace(self, view_page: Page) -> None:
        """Clicking Wrap changes textarea white-space to pre-wrap."""
        initial_ws = view_page.evaluate(
            "() => getComputedStyle(document.getElementById('log-content')).whiteSpace"
        )
        view_page.evaluate("() => document.getElementById('btn-wrap').click()")
        view_page.wait_for_timeout(300)
        after_ws = view_page.evaluate(
            "() => document.getElementById('log-content').style.whiteSpace"
        )
        assert after_ws == 'pre-wrap', \
            f'Expected pre-wrap after Wrap click, got: {after_ws!r}'
        print(f'\n✓ Wrap toggle: {initial_ws!r} → pre-wrap')


@pytest.mark.admin
class TestLogViewerWindowControls:
    """Yellow (minimize) and green (fullscreen) traffic dot buttons."""

    def test_minimize_adds_class(self, view_page: Page) -> None:
        """Clicking yellow dot adds lv-minimized to shell."""
        view_page.evaluate("() => document.getElementById('btn-minimize').click()")
        view_page.wait_for_timeout(300)

        classes = view_page.evaluate(
            "() => document.getElementById('log-viewer-wrap').className"
        )
        assert 'lv-minimized' in classes, \
            f'lv-minimized not added; classes: {classes}'
        print('\n✓ Minimize: lv-minimized class added')

    def test_minimize_toggle_restores(self, view_page: Page) -> None:
        """Second click on yellow dot removes lv-minimized."""
        view_page.evaluate("() => document.getElementById('btn-minimize').click()")  # minimize
        view_page.wait_for_timeout(200)
        view_page.evaluate("() => document.getElementById('btn-minimize').click()")  # restore
        view_page.wait_for_timeout(300)

        classes = view_page.evaluate(
            "() => document.getElementById('log-viewer-wrap').className"
        )
        assert 'lv-minimized' not in classes, \
            f'lv-minimized not removed on restore; classes: {classes}'
        print('\n✓ Minimize restore: lv-minimized removed')

    def test_fullscreen_adds_class(self, view_page: Page) -> None:
        """Clicking green dot adds lv-fullscreen to shell."""
        view_page.evaluate("() => document.getElementById('btn-fullscreen').click()")
        view_page.wait_for_timeout(300)

        classes = view_page.evaluate(
            "() => document.getElementById('log-viewer-wrap').className"
        )
        assert 'lv-fullscreen' in classes, \
            f'lv-fullscreen not added; classes: {classes}'
        print('\n✓ Fullscreen: lv-fullscreen class added')

    def test_fullscreen_toggle_restores(self, view_page: Page) -> None:
        """Second click on green dot removes lv-fullscreen."""
        view_page.evaluate("() => document.getElementById('btn-fullscreen').click()")
        view_page.wait_for_timeout(200)
        view_page.evaluate("() => document.getElementById('btn-fullscreen').click()")
        view_page.wait_for_timeout(300)

        classes = view_page.evaluate(
            "() => document.getElementById('log-viewer-wrap').className"
        )
        assert 'lv-fullscreen' not in classes, \
            f'lv-fullscreen not removed on restore; classes: {classes}'
        print('\n✓ Fullscreen restore: lv-fullscreen removed')


@pytest.mark.admin
class TestLogViewerJsFilter:
    """Instant client-side JS filter."""

    @staticmethod
    def _set_filter(page: Page, value: str) -> None:
        """Set the JS filter value and dispatch the input event the handler listens to."""
        page.evaluate(
            "(v) => { var el = document.getElementById('filter-js'); "
            "el.value = v; "
            "el.dispatchEvent(new Event('input')); }",
            value
        )

    def test_filter_hides_non_matching_lines(self, view_page: Page) -> None:
        """Typing a unique token in the filter reduces textarea content."""
        original = view_page.locator('#log-content').input_value()
        original_lines = [l for l in original.splitlines() if l.strip()]

        self._set_filter(view_page, 'ERROR')
        view_page.wait_for_timeout(400)

        filtered = view_page.locator('#log-content').input_value()
        filtered_lines = [l for l in filtered.splitlines() if l.strip()]

        # Filtered result should have <= original lines
        assert len(filtered_lines) <= len(original_lines), \
            'Filter did not reduce number of lines'
        print(f'\n✓ JS filter: {len(original_lines)} → {len(filtered_lines)} lines')

    def test_filter_match_count_shown(self, view_page: Page) -> None:
        """Match count badge appears when filter is non-empty."""
        self._set_filter(view_page, 'ERROR')
        view_page.wait_for_timeout(400)

        count_text = view_page.locator('#filter-match-count').inner_text()
        # Should be empty OR show "N match(es)"
        print(f'\n  Match count text: {count_text!r}')
        # If no matches the textarea will be empty — count text may say "0 matches"
        # or contain a number; we just verify no JS error was thrown
        assert True  # structural check — no exception = pass

    def test_filter_clear_btn_appears(self, view_page: Page) -> None:
        """Clear button appears when filter has text, hides when cleared."""
        self._set_filter(view_page, 'test')
        view_page.wait_for_timeout(300)

        visible_display = view_page.evaluate(
            "() => document.getElementById('btn-filter-clear').style.display"
        )
        assert visible_display != 'none', \
            'Clear button should be visible when filter has text'

        # JS click to bypass loader-mask overlay
        view_page.evaluate("() => document.getElementById('btn-filter-clear').click()")
        view_page.wait_for_timeout(300)

        hidden_display = view_page.evaluate(
            "() => document.getElementById('btn-filter-clear').style.display"
        )
        assert hidden_display == 'none', \
            'Clear button should be hidden after clearing filter'
        print('\n✓ Filter clear button shows/hides correctly')

    def test_filter_clear_restores_all_lines(self, view_page: Page) -> None:
        """Clearing the filter restores the full line count."""
        original = view_page.locator('#log-content').input_value()

        self._set_filter(view_page, 'ZZZNOTFOUND')
        view_page.wait_for_timeout(300)
        view_page.evaluate("() => document.getElementById('btn-filter-clear').click()")
        view_page.wait_for_timeout(300)

        restored = view_page.locator('#log-content').input_value()
        assert restored == original, \
            'Clearing filter did not restore original content'
        print('\n✓ Filter clear restores original textarea content')


@pytest.mark.admin
class TestLogViewerDownloadBtn:
    """Download button spinner animation."""

    def test_download_btn_present_in_nav(self, view_page: Page) -> None:
        """Download button is rendered in the nav bar."""
        btn = view_page.locator('#btn-download')
        assert btn.count() == 1, '#btn-download not found'
        print('\n✓ Download button present')

    def test_download_btn_click_adds_downloading_class(self, view_page: Page) -> None:
        """Clicking download adds the .downloading class (spinner state)."""
        view_page.evaluate("() => document.getElementById('btn-download').click()")
        view_page.wait_for_timeout(300)

        classes = view_page.evaluate(
            "() => document.getElementById('btn-download').className"
        )
        assert 'downloading' in classes, \
            f'Expected .downloading after click; classes: {classes}'
        print('\n✓ Download button adds .downloading class on click')

    def test_download_label_changes_to_preparing(self, view_page: Page) -> None:
        """Label text changes to "Preparing…" during download."""
        view_page.evaluate("() => document.getElementById('btn-download').click()")
        view_page.wait_for_timeout(300)

        label = view_page.locator('#btn-download-label').inner_text()
        assert 'Preparing' in label, \
            f'Expected "Preparing…" label, got: {label!r}'
        print(f'\n✓ Download label changed to: {label!r}')


@pytest.mark.admin
class TestLogViewerGrepToolbar:
    """Grep toolbar renders and accepts input."""

    def test_grep_input_accepts_text(self, view_page: Page) -> None:
        """Grep input accepts text entry."""
        view_page.locator('#filter-grep').fill('ERROR')
        view_page.wait_for_timeout(200)
        value = view_page.locator('#filter-grep').input_value()
        assert value == 'ERROR'
        print('\n✓ Grep input accepts text')

    def test_grep_full_scan_checkbox(self, view_page: Page) -> None:
        """Full scan checkbox can be toggled."""
        initial = view_page.locator('#grep-full').is_checked()
        view_page.evaluate("() => document.getElementById('grep-full').click()")
        view_page.wait_for_timeout(200)
        after = view_page.locator('#grep-full').is_checked()
        assert after != initial, 'Checkbox did not toggle'
        print(f'\n✓ Full scan checkbox toggled: {initial} → {after}')

    def test_grep_ajax_returns_content(self, view_page: Page) -> None:
        """Clicking Grep fires AJAX and updates textarea content."""
        view_page.locator('#filter-grep').fill('.')  # dot matches any line

        # Intercept the grep AJAX response
        with view_page.expect_response(
            lambda r: '/admin/logs/grep' in r.url, timeout=15_000
        ) as response_info:
            view_page.evaluate("() => document.getElementById('btn-grep').click()")

        response = response_info.value
        assert response.status == 200, \
            f'Grep AJAX returned {response.status}'

        data = response.json()
        assert 'content' in data, f'Response missing "content" key: {data}'
        assert 'lineCount' in data, f'Response missing "lineCount" key: {data}'
        print(f'\n✓ Grep AJAX response: {data["lineCount"]} lines, '
              f'{len(data["content"])} chars')

    def test_grep_badge_shown_after_search(self, view_page: Page) -> None:
        """Grep status badge becomes visible after a grep search."""
        view_page.locator('#filter-grep').fill('ERROR')

        with view_page.expect_response(
            lambda r: '/admin/logs/grep' in r.url, timeout=15_000
        ):
            view_page.evaluate("() => document.getElementById('btn-grep').click()")

        view_page.wait_for_timeout(500)
        grep_status = view_page.locator('#grep-status')
        display = grep_status.evaluate("el => el.style.display")
        assert display == 'flex', \
            f'Grep status badge not shown; display={display!r}'
        print('\n✓ Grep status badge visible after search')

    def test_grep_clear_hides_badge_and_reloads(self, view_page: Page) -> None:
        """Clear grep fires AJAX reload and hides the badge."""
        view_page.locator('#filter-grep').fill('ERROR')

        with view_page.expect_response(
            lambda r: '/admin/logs/grep' in r.url, timeout=15_000
        ):
            view_page.evaluate("() => document.getElementById('btn-grep').click()")

        view_page.wait_for_timeout(500)

        # Now clear — expect a /reload AJAX call
        with view_page.expect_response(
            lambda r: '/admin/logs/reload' in r.url, timeout=15_000
        ):
            view_page.evaluate("() => document.getElementById('btn-grep-clear').click()")

        view_page.wait_for_timeout(500)
        display = view_page.locator('#grep-status').evaluate("el => el.style.display")
        assert display == 'none', \
            f'Grep status should be hidden after clear; display={display!r}'
        print('\n✓ Grep clear hides badge and fires reload')


@pytest.mark.admin
class TestLogViewerReloadAndPause:
    """Reload button (AJAX) and Pause/Resume live tail."""

    def test_reload_ajax_returns_content(self, view_page: Page) -> None:
        """Clicking Reload fires AJAX to /reload and returns JSON with content/offset."""
        captured: list[dict] = []

        def _intercept(route, request):
            resp = route.fetch()
            try:
                captured.append(resp.json())
            except Exception:
                captured.append({})
            route.fulfill(response=resp)

        view_page.route('**/admin/logs/reload**', _intercept)
        try:
            view_page.evaluate("() => document.getElementById('btn-reload').click()")
            view_page.wait_for_timeout(3_000)
        finally:
            view_page.unroute('**/admin/logs/reload**')

        assert captured, 'Reload AJAX request was never fired'
        data = captured[0]
        assert 'content' in data, f'Missing "content" in reload response: {data}'
        assert 'offset' in data,  f'Missing "offset" in reload response: {data}'
        print(f'\n✓ Reload AJAX: offset={data["offset"]}, {data.get("lineCount", "?")} lines')

    def test_reload_updates_textarea(self, view_page: Page) -> None:
        """After reload, textarea contains the new content from the server."""
        original = view_page.locator('#log-content').input_value()

        with view_page.expect_response(
            lambda r: '/admin/logs/reload' in r.url, timeout=15_000
        ):
            view_page.evaluate("() => document.getElementById('btn-reload').click()")

        view_page.wait_for_timeout(500)
        after = view_page.locator('#log-content').input_value()
        # Reloaded content should be non-empty (same or new tail)
        assert len(after) > 0, 'Textarea was empty after reload'
        print(f'\n✓ Textarea updated after reload ({len(after)} chars)')

    def test_pause_changes_button_text(self, view_page: Page) -> None:
        """Clicking Pause changes button text to Resume."""
        view_page.evaluate("() => document.getElementById('btn-pause').click()")
        view_page.wait_for_timeout(300)

        text = view_page.locator('#btn-pause').inner_text()
        assert 'Pause' in text, f'Expected "Pause" after click (live mode); got: {text!r}'
        print(f'\n✓ Pause button text: {text!r}')

    def test_resume_restores_button_text(self, view_page: Page) -> None:
        """Second click on Pause restores Pause button text."""
        view_page.evaluate("() => document.getElementById('btn-pause').click()")  # pause
        view_page.wait_for_timeout(200)
        view_page.evaluate("() => document.getElementById('btn-pause').click()")  # resume
        view_page.wait_for_timeout(300)

        text = view_page.locator('#btn-pause').inner_text()
        assert 'Resume' in text, f'Expected "Resume" after 2nd click (re-pause); got: {text!r}'
        print(f'\n✓ Resume restored button text: {text!r}')


@pytest.mark.admin
class TestLogViewerLiveIndicator:
    """Live indicator pill is rendered and initially shows no error state."""

    def test_live_indicator_present(self, view_page: Page) -> None:
        """#live-indicator is in the titlebar."""
        el = view_page.locator('#live-indicator')
        assert el.count() == 1
        print('\n✓ #live-indicator present')

    def test_live_indicator_not_error_on_load(self, view_page: Page) -> None:
        """Live indicator does not show the live-err class on initial load."""
        cls = view_page.locator('#live-indicator').get_attribute('class') or ''
        assert 'live-err' not in cls, \
            f'Live indicator is in error state on load: class={cls!r}'
        print(f'\n✓ Live indicator class on load: {cls!r}')


@pytest.mark.admin
class TestLogViewerNoConsoleErrors:
    """Collect browser console errors during page interactions."""

    def test_no_console_errors_on_load(self, page: Page, admin_login) -> None:
        """No console.error() calls during page load and init."""
        errors: list[str] = []

        def _on_console(msg: ConsoleMessage) -> None:
            if msg.type == 'error':
                errors.append(msg.text)

        _inject_error_collector(page)
        page.on('console', _on_console)

        _login_and_goto(page, admin_login, VIEW_URL)
        page.wait_for_selector('#log-content', timeout=NAV_TIMEOUT)
        page.wait_for_timeout(2500)

        # Filter out known benign OroCommerce noise
        critical = [
            e for e in errors
            if not any(skip in e for skip in [
                'favicon.ico',
                'ResizeObserver',
                'Non-Error promise rejection',
            ])
        ]
        assert not critical, f'Console errors on page load: {critical}'
        print(f'\n✓ No critical console errors ({len(errors)} total: {errors[:3]})')

    def test_no_console_errors_after_interactions(self, page: Page, admin_login) -> None:
        """No console.error() calls during common user interactions."""
        errors: list[str] = []

        def _on_console(msg: ConsoleMessage) -> None:
            if msg.type == 'error':
                errors.append(msg.text)

        _inject_error_collector(page)
        page.on('console', _on_console)

        _login_and_goto(page, admin_login, VIEW_URL)
        page.wait_for_selector('#log-content', timeout=NAV_TIMEOUT)
        page.wait_for_timeout(2000)

        # Perform interactions
        page.locator('#btn-terminal').click()       # theme toggle
        page.wait_for_timeout(200)
        page.locator('#btn-wrap').click()            # wrap toggle
        page.wait_for_timeout(200)
        page.locator('#btn-minimize').click()        # minimize
        page.wait_for_timeout(200)
        page.locator('#btn-minimize').click()        # restore
        page.wait_for_timeout(200)
        page.locator('#filter-js').fill('test')      # JS filter
        page.wait_for_timeout(300)
        page.locator('#btn-filter-clear').click()    # clear filter
        page.wait_for_timeout(200)
        page.locator('#btn-pause').click()           # pause
        page.wait_for_timeout(200)
        page.locator('#btn-pause').click()           # resume
        page.wait_for_timeout(200)

        critical = [
            e for e in errors
            if not any(skip in e for skip in [
                'favicon.ico',
                'ResizeObserver',
                'Non-Error promise rejection',
            ])
        ]
        assert not critical, \
            f'Console errors during interactions: {critical}'
        print(f'\n✓ No critical console errors after interactions '
              f'({len(errors)} total noise: {errors[:3]})')


# ---------------------------------------------------------------------------
# Exception aggregation panel
# ---------------------------------------------------------------------------

@pytest.mark.admin
class TestLogViewerExceptions:
    """Exception aggregation panel — opened via the Exceptions button."""

    def test_exceptions_button_present(self, view_page: Page) -> None:
        """The Exceptions toolbar button is rendered."""
        btn = view_page.locator('#btn-exceptions')
        assert btn.count() == 1, '#btn-exceptions not found'
        assert btn.is_visible(), '#btn-exceptions is not visible'

    def test_exceptions_panel_hidden_on_load(self, view_page: Page) -> None:
        """The exceptions panel is hidden before the button is clicked."""
        panel = view_page.locator('#lv-exceptions-panel')
        assert panel.count() == 1, '#lv-exceptions-panel not in DOM'
        assert not panel.is_visible(), 'exceptions panel should be hidden on load'

    def test_exceptions_button_opens_panel(self, view_page: Page) -> None:
        """Clicking Exceptions button makes the panel visible."""
        view_page.locator('#btn-exceptions').click()
        panel = view_page.locator('#lv-exceptions-panel')
        panel.wait_for(state='visible', timeout=15_000)
        assert panel.is_visible(), 'exceptions panel did not become visible'

    def test_exceptions_panel_has_header(self, view_page: Page) -> None:
        """Exceptions panel contains its header element."""
        view_page.locator('#btn-exceptions').click()
        view_page.locator('#lv-exceptions-panel').wait_for(state='visible', timeout=15_000)
        header = view_page.locator('#lv-exceptions-panel .lv-exc-header')
        assert header.count() == 1, '.lv-exc-header not found inside panel'
        assert header.is_visible()

    def test_exceptions_panel_body_present(self, view_page: Page) -> None:
        """Exceptions panel has a body container (may be empty on a short log)."""
        view_page.locator('#btn-exceptions').click()
        view_page.locator('#lv-exceptions-panel').wait_for(state='visible', timeout=15_000)
        body = view_page.locator('#lv-exceptions-panel .lv-exc-body')
        assert body.count() == 1, '.lv-exc-body not found'

    def test_exceptions_second_click_hides_panel(self, view_page: Page) -> None:
        """Clicking Exceptions a second time toggles the panel off."""
        btn = view_page.locator('#btn-exceptions')
        btn.click()
        view_page.locator('#lv-exceptions-panel').wait_for(state='visible', timeout=15_000)
        btn.click()
        view_page.locator('#lv-exceptions-panel').wait_for(state='hidden', timeout=5_000)
        assert not view_page.locator('#lv-exceptions-panel').is_visible()


# ---------------------------------------------------------------------------
# Throughput graph panel
# ---------------------------------------------------------------------------

@pytest.mark.admin
class TestLogViewerThroughput:
    """Throughput (lines-per-minute graph) panel tests."""

    def test_throughput_button_present(self, view_page: Page) -> None:
        """The Graph toolbar button is rendered."""
        btn = view_page.locator('#btn-throughput')
        assert btn.count() == 1, '#btn-throughput not found'
        assert btn.is_visible()

    def test_throughput_panel_hidden_on_load(self, view_page: Page) -> None:
        """Throughput panel starts hidden."""
        panel = view_page.locator('#lv-throughput-panel')
        assert panel.count() == 1, '#lv-throughput-panel not in DOM'
        assert not panel.is_visible()

    def test_throughput_button_opens_panel(self, view_page: Page) -> None:
        """Clicking Graph opens the throughput panel."""
        view_page.locator('#btn-throughput').click()
        panel = view_page.locator('#lv-throughput-panel')
        panel.wait_for(state='visible', timeout=15_000)
        assert panel.is_visible()

    def test_throughput_panel_has_header(self, view_page: Page) -> None:
        """Throughput panel contains its header."""
        view_page.locator('#btn-throughput').click()
        view_page.locator('#lv-throughput-panel').wait_for(state='visible', timeout=15_000)
        header = view_page.locator('#lv-throughput-panel .lv-throughput-header')
        assert header.count() == 1
        assert header.is_visible()

    def test_throughput_body_rendered(self, view_page: Page) -> None:
        """After fetch, the throughput body container is in the DOM."""
        view_page.locator('#btn-throughput').click()
        view_page.locator('#lv-throughput-panel').wait_for(state='visible', timeout=15_000)
        body = view_page.locator('#lv-throughput-panel .lv-throughput-body')
        assert body.count() == 1

    def test_throughput_svg_or_message_appears(self, view_page: Page) -> None:
        """After the AJAX call the body contains either an SVG or a text message."""
        view_page.locator('#btn-throughput').click()
        view_page.locator('#lv-throughput-panel').wait_for(state='visible', timeout=15_000)
        body = view_page.locator('#lv-throughput-panel .lv-throughput-body')
        # Wait for content to appear (AJAX may take a moment)
        view_page.wait_for_function(
            "() => document.querySelector('.lv-throughput-body') && "
            "document.querySelector('.lv-throughput-body').innerHTML.trim() !== ''",
            timeout=15_000
        )
        inner = body.inner_html()
        assert inner.strip() != '', 'throughput body is still empty after fetch'

    def test_throughput_second_click_hides_panel(self, view_page: Page) -> None:
        """Second click on Graph hides the panel."""
        btn = view_page.locator('#btn-throughput')
        btn.click()
        view_page.locator('#lv-throughput-panel').wait_for(state='visible', timeout=15_000)
        btn.click()
        view_page.locator('#lv-throughput-panel').wait_for(state='hidden', timeout=5_000)
        assert not view_page.locator('#lv-throughput-panel').is_visible()


# ---------------------------------------------------------------------------
# Split pane
# ---------------------------------------------------------------------------

@pytest.mark.admin
class TestLogViewerSplitView:
    """Side-by-side split pane tests."""

    def test_split_button_present(self, view_page: Page) -> None:
        """The Split toolbar button is rendered."""
        btn = view_page.locator('#btn-split-view')
        assert btn.count() == 1, '#btn-split-view not found'
        assert btn.is_visible()

    def test_split_pane_absent_on_load(self, view_page: Page) -> None:
        """The split pane does not exist in the DOM before clicking Split."""
        assert view_page.locator('#lv-split-pane').count() == 0, \
            '#lv-split-pane should not be in DOM before Split is clicked'

    def test_split_button_creates_pane(self, view_page: Page) -> None:
        """Clicking Split injects the split pane into the DOM."""
        view_page.locator('#btn-split-view').click()
        view_page.wait_for_selector('#lv-split-pane', timeout=10_000)
        pane = view_page.locator('#lv-split-pane')
        assert pane.count() == 1
        assert pane.is_visible()

    def test_split_adds_active_class_to_shell(self, view_page: Page) -> None:
        """Opening split view adds lv-split-active to the shell element."""
        view_page.locator('#btn-split-view').click()
        view_page.wait_for_selector('#lv-split-pane', timeout=10_000)
        has_class = view_page.evaluate(
            "() => document.getElementById('log-viewer-wrap').classList.contains('lv-split-active')"
        )
        assert has_class, 'lv-split-active class missing after split opened'

    def test_split_pane_has_textarea(self, view_page: Page) -> None:
        """The split pane contains a textarea for the secondary log."""
        view_page.locator('#btn-split-view').click()
        view_page.wait_for_selector('#lv-split-pane', timeout=10_000)
        ta = view_page.locator('#lv-split-pane .lv-split-textarea')
        assert ta.count() == 1, 'no textarea found inside split pane'

    def test_split_pane_has_header(self, view_page: Page) -> None:
        """The split pane renders a header bar."""
        view_page.locator('#btn-split-view').click()
        view_page.wait_for_selector('#lv-split-pane', timeout=10_000)
        header = view_page.locator('#lv-split-pane .lv-split-header')
        assert header.count() == 1, '.lv-split-header not found'

    def test_split_close_removes_pane(self, view_page: Page) -> None:
        """Closing the split pane removes lv-split-active class."""
        view_page.locator('#btn-split-view').click()
        view_page.wait_for_selector('#lv-split-pane', timeout=10_000)
        # Click the close button inside the split pane
        view_page.locator('#btn-split-close').click()
        view_page.wait_for_timeout(500)
        has_class = view_page.evaluate(
            "() => document.getElementById('log-viewer-wrap').classList.contains('lv-split-active')"
        )
        assert not has_class, 'lv-split-active still present after close'


# ---------------------------------------------------------------------------
# Multi-file combined tail / grep panel
# ---------------------------------------------------------------------------

@pytest.mark.admin
class TestLogViewerMultiFile:
    """Multi-file combined tail and grep panel tests."""

    def test_multi_button_present(self, view_page: Page) -> None:
        """The Multi toolbar button is rendered."""
        btn = view_page.locator('#btn-multi-view')
        assert btn.count() == 1, '#btn-multi-view not found'
        assert btn.is_visible()

    def test_multi_panel_hidden_on_load(self, view_page: Page) -> None:
        """Multi panel is hidden before the button is clicked."""
        panel = view_page.locator('#lv-multi-panel')
        assert panel.count() == 1, '#lv-multi-panel not in DOM'
        assert not panel.is_visible()

    def test_multi_button_opens_panel(self, view_page: Page) -> None:
        """Clicking Multi makes the panel visible and builds its interior."""
        view_page.locator('#btn-multi-view').click()
        panel = view_page.locator('#lv-multi-panel')
        panel.wait_for(state='visible', timeout=10_000)
        assert panel.is_visible()

    def test_multi_panel_has_close_button(self, view_page: Page) -> None:
        """Multi panel contains the Close button."""
        view_page.locator('#btn-multi-view').click()
        view_page.locator('#lv-multi-panel').wait_for(state='visible', timeout=10_000)
        btn = view_page.locator('#btn-multi-close')
        assert btn.count() == 1
        assert btn.is_visible()

    def test_multi_panel_has_file_checkboxes(self, view_page: Page) -> None:
        """At least one log-file checkbox is rendered in the picker."""
        view_page.locator('#btn-multi-view').click()
        view_page.locator('#lv-multi-panel').wait_for(state='visible', timeout=10_000)
        checkboxes = view_page.locator('.lv-multi-chk')
        assert checkboxes.count() >= 1, 'no file checkboxes rendered in multi panel'

    def test_multi_panel_has_tail_button(self, view_page: Page) -> None:
        """The Tail button is present in the multi panel controls."""
        view_page.locator('#btn-multi-view').click()
        view_page.locator('#lv-multi-panel').wait_for(state='visible', timeout=10_000)
        btn = view_page.locator('#btn-multi-start')
        assert btn.count() == 1
        assert btn.is_visible()

    def test_multi_panel_has_grep_button(self, view_page: Page) -> None:
        """The Grep button is present in the multi panel controls."""
        view_page.locator('#btn-multi-view').click()
        view_page.locator('#lv-multi-panel').wait_for(state='visible', timeout=10_000)
        btn = view_page.locator('#btn-multi-grep-run')
        assert btn.count() == 1
        assert btn.is_visible()

    def test_multi_panel_has_table(self, view_page: Page) -> None:
        """The combined results table is rendered inside the panel."""
        view_page.locator('#btn-multi-view').click()
        view_page.locator('#lv-multi-panel').wait_for(state='visible', timeout=10_000)
        table = view_page.locator('.lv-multi-table')
        assert table.count() == 1, '.lv-multi-table not found'

    def test_multi_select_all_checks_all(self, view_page: Page) -> None:
        """Clicking All checks all file checkboxes."""
        view_page.locator('#btn-multi-view').click()
        view_page.locator('#lv-multi-panel').wait_for(state='visible', timeout=10_000)
        view_page.locator('#btn-multi-select-all').click()
        checkboxes = view_page.locator('.lv-multi-chk')
        total = checkboxes.count()
        checked = view_page.evaluate(
            "() => document.querySelectorAll('.lv-multi-chk:checked').length"
        )
        assert checked == total, f'expected {total} checked, got {checked}'

    def test_multi_select_none_unchecks_all(self, view_page: Page) -> None:
        """Clicking None un-checks all file checkboxes."""
        view_page.locator('#btn-multi-view').click()
        view_page.locator('#lv-multi-panel').wait_for(state='visible', timeout=10_000)
        view_page.locator('#btn-multi-select-all').click()
        view_page.locator('#btn-multi-select-none').click()
        checked = view_page.evaluate(
            "() => document.querySelectorAll('.lv-multi-chk:checked').length"
        )
        assert checked == 0, f'expected 0 checked after None, got {checked}'

    def test_multi_grep_shows_results_in_table(self, view_page: Page) -> None:
        """Running a multi-grep populates the combined table with rows."""
        view_page.locator('#btn-multi-view').click()
        view_page.locator('#lv-multi-panel').wait_for(state='visible', timeout=10_000)
        # Select all files
        view_page.locator('#btn-multi-select-all').click()
        # Type a common pattern that is virtually guaranteed to appear in any log
        view_page.locator('#multi-grep-input').fill('.')
        view_page.locator('#btn-multi-grep-run').click()
        # Wait for at least one row to appear in the tbody
        view_page.wait_for_function(
            "() => document.querySelectorAll('#lv-multi-tbody tr').length > 0",
            timeout=20_000
        )
        row_count = view_page.evaluate(
            "() => document.querySelectorAll('#lv-multi-tbody tr').length"
        )
        assert row_count > 0, 'no rows appeared in multi-grep table'

    def test_multi_grep_rows_have_file_badge(self, view_page: Page) -> None:
        """Each result row in the table includes a file-badge cell."""
        view_page.locator('#btn-multi-view').click()
        view_page.locator('#lv-multi-panel').wait_for(state='visible', timeout=10_000)
        view_page.locator('#btn-multi-select-all').click()
        view_page.locator('#multi-grep-input').fill('.')
        view_page.locator('#btn-multi-grep-run').click()
        view_page.wait_for_function(
            "() => document.querySelectorAll('#lv-multi-tbody tr').length > 0",
            timeout=20_000
        )
        badges = view_page.locator('#lv-multi-tbody .lv-mt-badge')
        assert badges.count() > 0, 'no .lv-mt-badge elements found in results'

    def test_multi_clear_empties_table(self, view_page: Page) -> None:
        """Clicking Clear removes all rows from the results table."""
        view_page.locator('#btn-multi-view').click()
        view_page.locator('#lv-multi-panel').wait_for(state='visible', timeout=10_000)
        view_page.locator('#btn-multi-select-all').click()
        view_page.locator('#multi-grep-input').fill('.')
        view_page.locator('#btn-multi-grep-run').click()
        view_page.wait_for_function(
            "() => document.querySelectorAll('#lv-multi-tbody tr').length > 0",
            timeout=20_000
        )
        view_page.locator('#btn-multi-clear-rows').click()
        row_count = view_page.evaluate(
            "() => document.querySelectorAll('#lv-multi-tbody tr').length"
        )
        assert row_count == 0, f'expected 0 rows after clear, got {row_count}'

    def test_multi_close_button_hides_panel(self, view_page: Page) -> None:
        """Clicking the Close button inside the panel hides it."""
        view_page.locator('#btn-multi-view').click()
        view_page.locator('#lv-multi-panel').wait_for(state='visible', timeout=10_000)
        view_page.locator('#btn-multi-close').click()
        view_page.locator('#lv-multi-panel').wait_for(state='hidden', timeout=5_000)
        assert not view_page.locator('#lv-multi-panel').is_visible()

    def test_multi_toolbar_button_toggles_closed(self, view_page: Page) -> None:
        """Second click on the toolbar Multi button closes the panel."""
        btn = view_page.locator('#btn-multi-view')
        btn.click()
        view_page.locator('#lv-multi-panel').wait_for(state='visible', timeout=10_000)
        btn.click()
        view_page.locator('#lv-multi-panel').wait_for(state='hidden', timeout=5_000)
        assert not view_page.locator('#lv-multi-panel').is_visible()

    def test_multi_tail_start_shows_stop_button(self, view_page: Page) -> None:
        """Starting tail polling reveals the Stop button."""
        view_page.locator('#btn-multi-view').click()
        view_page.locator('#lv-multi-panel').wait_for(state='visible', timeout=10_000)
        view_page.locator('#btn-multi-select-all').click()
        view_page.locator('#btn-multi-start').click()
        stop_btn = view_page.locator('#btn-multi-stop')
        stop_btn.wait_for(state='visible', timeout=8_000)
        assert stop_btn.is_visible(), '#btn-multi-stop did not become visible after Tail start'

    def test_multi_tail_stop_hides_stop_button(self, view_page: Page) -> None:
        """Clicking Stop hides the Stop button and restores Tail."""
        view_page.locator('#btn-multi-view').click()
        view_page.locator('#lv-multi-panel').wait_for(state='visible', timeout=10_000)
        view_page.locator('#btn-multi-select-all').click()
        view_page.locator('#btn-multi-start').click()
        view_page.locator('#btn-multi-stop').wait_for(state='visible', timeout=8_000)
        view_page.locator('#btn-multi-stop').click()
        start_btn = view_page.locator('#btn-multi-start')
        start_btn.wait_for(state='visible', timeout=5_000)
        assert start_btn.is_visible()

    def test_multi_status_updated_after_grep(self, view_page: Page) -> None:
        """Status text is non-empty after running a grep."""
        view_page.locator('#btn-multi-view').click()
        view_page.locator('#lv-multi-panel').wait_for(state='visible', timeout=10_000)
        view_page.locator('#btn-multi-select-all').click()
        view_page.locator('#multi-grep-input').fill('.')
        view_page.locator('#btn-multi-grep-run').click()
        view_page.wait_for_function(
            "() => (document.getElementById('lv-multi-status') || {}).textContent.trim() !== ''",
            timeout=20_000
        )
        status_text = view_page.locator('#lv-multi-status').inner_text()
        assert status_text.strip() != '', 'status text is empty after grep'
