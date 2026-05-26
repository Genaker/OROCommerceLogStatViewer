"""
Local E2E configuration for LogViewerBundle tests.

Self-contained copy of the relevant subset of tests/e2e/config.py so this
bundle's tests run without needing the root tests/e2e/ directory on sys.path.

All values are read from environment variables, which are loaded from
.env-app.local / .env-app by conftest.py before any test module is imported.
"""
import os


class E2EConfig:
    """Configuration for LogViewerBundle Playwright tests."""

    SCHEME: str = os.environ.get('ORO_TEST_HTTP_SCHEME', 'http')
    HOST:   str = os.environ.get('ORO_TEST_HTTP_HOST',   'localhost')
    PORT:   str = os.environ.get('ORO_TEST_HTTP_PORT',   '8000')

    BASE_URL: str = f"{SCHEME}://{HOST}:{PORT}"

    # ORO_TEST_ADMIN_USERNAME is on a comment-prefixed line in .env-app.local so
    # the env parser cannot extract it; hard-code the known default here.
    ADMIN_USERNAME: str = os.environ.get('ORO_TEST_ADMIN_USERNAME', 'admin123')
    ADMIN_PASSWORD: str = os.environ.get('ORO_TEST_ADMIN_PASSWORD', 'AdminTest123')

    ADMIN_LOGIN_URL:      str = f"{BASE_URL}/admin/user/login"
    ADMIN_LOG_VIEWER_URL: str = f"{BASE_URL}/admin/logs"

    @classmethod
    def url(cls, path: str) -> str:
        if not path.startswith('/'):
            path = f'/{path}'
        return f"{cls.BASE_URL}{path}"
