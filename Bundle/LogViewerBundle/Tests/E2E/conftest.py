"""
Local conftest for LogViewerBundle E2E tests.

Fully self-contained — no dependency on the root tests/e2e/ directory.
Run the bundle's tests standalone:

    /oro-ee/var/tmp/venv/bin/pytest \
        src/Egerdau/Bundle/LogViewerBundle/Tests/E2E/ -v

Performance: admin login is performed ONCE per session. The resulting
storage state (cookies + localStorage) is saved to a temp file and reused
by every test context, eliminating per-test login round-trips.
"""
import os
import json
import tempfile
from pathlib import Path
import pytest
from playwright.sync_api import Browser, BrowserContext, Page

# ---------------------------------------------------------------------------
# Load .env-app.local (walks up to workspace root)
# ---------------------------------------------------------------------------

def _load_env_file(path: str) -> None:
    """Parse KEY=VALUE file and set missing env vars."""
    try:
        for line in Path(path).read_text().splitlines():
            line = line.strip()
            if not line or line.startswith('#') or '=' not in line:
                continue
            k, _, v = line.partition('=')
            k = k.strip()
            v = v.strip()
            if k not in os.environ:
                os.environ[k] = v
    except FileNotFoundError:
        pass

_ROOT = Path(__file__).resolve().parents[6]  # /oro-ee
_load_env_file(str(_ROOT / '.env-app.local'))
_load_env_file(str(_ROOT / '.env-app'))

# Local config — no external sys.path manipulation needed
from config import E2EConfig  # noqa: E402  (same directory as this conftest)

_BASE_URL   = E2EConfig.BASE_URL
_ADMIN_USER = E2EConfig.ADMIN_USERNAME
_ADMIN_PASS = E2EConfig.ADMIN_PASSWORD
_LOGIN_URL  = E2EConfig.ADMIN_LOGIN_URL

# ---------------------------------------------------------------------------
# Fixtures
# ---------------------------------------------------------------------------

@pytest.fixture(scope='session')
def browser():
    from playwright.sync_api import sync_playwright
    with sync_playwright() as p:
        b = p.chromium.launch(headless=True)
        yield b
        b.close()


@pytest.fixture(scope='session')
def admin_storage_state(browser: Browser) -> str:
    """Login to admin once per session and return path to saved storage state.

    All subsequent test contexts load this state instead of re-authenticating,
    reducing each test's setup from ~15s (3 HTTP round-trips) to <1s.
    """
    _login_check = f'{_BASE_URL}/admin/user/login-check'
    state_file = tempfile.mktemp(suffix='_admin_auth.json', dir='/oro-ee/var/tmp')

    ctx = browser.new_context(service_workers='block')
    pg = ctx.new_page()
    try:
        pg.goto(_LOGIN_URL, timeout=120_000, wait_until='domcontentloaded')
        csrf = pg.evaluate(
            "() => document.querySelector('input[name=\"_csrf_token\"]').value"
        )
        pg.request.post(
            _login_check,
            timeout=90_000,
            form={
                '_username':    _ADMIN_USER,
                '_password':    _ADMIN_PASS,
                '_csrf_token':  csrf,
                '_target_path': '',
            },
        )
        pg.goto(f'{_BASE_URL}/admin/', timeout=120_000, wait_until='domcontentloaded')
        assert 'login' not in pg.url.lower(), \
            f'Session login failed — still on: {pg.url}'
        ctx.storage_state(path=state_file)
        print(f'\n[conftest] Admin session stored → {state_file}')
    finally:
        pg.close()
        ctx.close()

    yield state_file

    try:
        os.unlink(state_file)
    except FileNotFoundError:
        pass


@pytest.fixture
def page(browser: Browser, admin_storage_state: str) -> Page:
    """Return a new page pre-authenticated via saved session cookies."""
    ctx = browser.new_context(
        service_workers='block',
        storage_state=admin_storage_state,
    )
    pg = ctx.new_page()
    yield pg
    pg.close()
    ctx.close()


@pytest.fixture
def admin_login(admin_storage_state: str):
    """Compatibility shim — session auth is already loaded into each page context.

    The callable still accepts (page, username, password) for call-site
    compatibility, but skips full re-authentication unless the page's current
    URL indicates the session has expired (rare).
    """
    def _ensure_logged_in(
        page: Page,
        username: str = _ADMIN_USER,
        password: str = _ADMIN_PASS,
    ) -> None:
        # Session cookies are already in the context; verify quickly.
        # If the page landed on the login form, fall back to full auth.
        if 'login' in page.url.lower():
            _login_check = f'{_BASE_URL}/admin/user/login-check'
            page.goto(_LOGIN_URL, timeout=120_000, wait_until='domcontentloaded')
            csrf = page.evaluate(
                "() => document.querySelector('input[name=\"_csrf_token\"]').value"
            )
            page.request.post(
                _login_check,
                timeout=90_000,
                form={
                    '_username':    username,
                    '_password':    password,
                    '_csrf_token':  csrf,
                    '_target_path': '',
                },
            )
            page.goto(f'{_BASE_URL}/admin/', timeout=120_000, wait_until='domcontentloaded')
            assert 'login' not in page.url.lower(), \
                f'Admin login failed — still on: {page.url}'

    return _ensure_logged_in


def pytest_configure(config):
    config.addinivalue_line('markers', 'admin: admin back-office tests')
    config.addinivalue_line('markers', 'sql_issues: SQL Issue Tracker E2E tests')

