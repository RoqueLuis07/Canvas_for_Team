"""Canvas for Teams - Main FastAPI Application"""
import logging
import sys
from pathlib import Path

from contextlib import asynccontextmanager
from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates

# Configure logging before imports
import sentry_sdk

from app.core.config import settings

if settings.sentry_dsn:
    sentry_sdk.init(
        dsn=settings.sentry_dsn,
        send_default_pii=False,
        traces_sample_rate=1.0,
    )

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(name)s - %(levelname)s - %(message)s",
    handlers=[
        logging.StreamHandler(sys.stdout),
        logging.FileHandler("app.log"),
    ]
)
logger = logging.getLogger(__name__)

from app.services import auth as auth_service
from app.core import database, cache as _cache
from app.routers import (
    audit,
    auth,
    canvas,
    excel,
    ingreso,
    jobs,
    profile,
    web,
    sync,
    egreso,
    reports,
    materias,
    cpel,
    actas,
    diplomado_sheets,
)
from app.routers import matriculacion
import asyncio
from app.routers.teams import teams_mgmt, users as teams_users

# Setup paths
BASE_DIR = Path(__file__).parent
STATIC_DIR = BASE_DIR.parent.parent / "Frontend" / "static"
TEMPLATES_DIR = BASE_DIR.parent.parent / "Frontend" / "templates"


# Initialize databases on startup
@asynccontextmanager
async def lifespan(app: FastAPI):
    from app.core.database import init_db
    from app.core.audit_log import init_audit_db
    from app.core.jobs import init_jobs_db
    from app.core.actas_db import init_actas_db
    from app.core.diplomado_sheets_db import init_diplomado_sheets_db
    try:
        await init_db()
        await asyncio.to_thread(init_audit_db)
        await asyncio.to_thread(init_jobs_db)
        await asyncio.to_thread(init_actas_db)
        await asyncio.to_thread(init_diplomado_sheets_db)
    except Exception as e:
        logger.error(f"Failed to initialize databases: {e}")
        
    yield
    from app.services import canvas_client, teams_client
    from app.core import database as db
    try:
        await canvas_client.close_client()
        await teams_client.close_client()
        await db.close_db()
    except Exception as e:
        logger.error(f"Failed to close connections cleanly: {e}")

# Create FastAPI app
app = FastAPI(
    title="Canvas for Teams API",
    description="Integration between Canvas LMS and Microsoft Teams",
    version="1.0.0",
    lifespan=lifespan,
)

# --- SlowAPI Rate Limiting ---
from slowapi import Limiter, _rate_limit_exceeded_handler
from slowapi.util import get_remote_address
from slowapi.middleware import SlowAPIMiddleware
from slowapi.errors import RateLimitExceeded

limiter = Limiter(key_func=get_remote_address, default_limits=["100/minute"])
app.state.limiter = limiter
app.add_exception_handler(RateLimitExceeded, _rate_limit_exceeded_handler)
app.add_middleware(SlowAPIMiddleware)
# -----------------------------

# Security middleware
from app.middleware.security import SecurityMiddleware
app.add_middleware(SecurityMiddleware)

# CORS middleware (Strict)
# En producción, solo permitimos el origen de la propia app. En local, localhost.
allowed_origins = [
    settings.site_url.rstrip("/"),
    "http://localhost:3000",
    "http://127.0.0.1:3000"
]

app.add_middleware(
    CORSMiddleware,
    allow_origins=allowed_origins,
    allow_credentials=True,
    allow_methods=["GET", "POST", "PUT", "DELETE", "PATCH", "OPTIONS"],
    allow_headers=["*"],
)

# Auth gate — exige sesión válida (admin autorizado) en todo el sistema
# salvo /auth/*, /ui/login, /health, /ping y /static/*
from app.middleware.auth_gate import AuthGateMiddleware
app.add_middleware(AuthGateMiddleware)

# Audit middleware
from app.middleware.audit import AuditMiddleware
app.add_middleware(AuditMiddleware)

# Mount static files
if STATIC_DIR.exists():
    app.mount("/static", StaticFiles(directory=str(STATIC_DIR)), name="static")

# Setup templates
templates = Jinja2Templates(directory=str(TEMPLATES_DIR))

# Health check endpoints
@app.get("/health", tags=["Health"])
async def health_check():
    """Basic health check."""
    try:
        stats = await get_stats()
        return {
            "status": "ok",
            "environment": settings.environment,
            **stats
        }
    except Exception as e:
        logger.error(f"Health check error: {e}")
        return {
            "status": "degraded",
            "error": str(e)
        }


@app.get("/stats", tags=["Health"])
async def get_stats():
    """Get application statistics."""
    try:
        courses = await database.count_courses()
        return {
            "courses": courses,
            "canvas_courses": courses,
            "canvas_users": await database.count_canvas_users(),
            "azure_users": await database.count_azure_users(),
        }
    except Exception as e:
        logger.error(f"Stats error: {e}", exc_info=True)
        return {
            "courses": 0,
            "canvas_courses": 0,
            "canvas_users": 0,
            "azure_users": 0,
            "error": str(e)
        }


@app.get("/ping", tags=["Health"])
async def ping():
    """Simple ping endpoint."""
    return {"pong": True}


@app.post("/cache/clear", tags=["Health"])
async def clear_cache():
    """Clears the in-memory cache."""
    removed = _cache.clear_all()
    return {"cleared": removed}


# Include routers with error handling
routers_to_load = [
    ("Auth", auth.router),
    ("Canvas", canvas.router),
    ("Excel", excel.router),
    ("Ingreso", ingreso.router),
    ("Matriculacion", matriculacion.router),
    ("Egreso", egreso.router),
    ("Jobs", jobs.router),
    ("Profile", profile.router),
    ("Audit", audit.router),
    ("Teams · Teams", teams_mgmt.router),
    ("Teams · Users", teams_users.router),
    ("Web", web.router),
    ("Sync", sync.router),
    ("Reports", reports.router),
    ("Materias", materias.router),
    ("CPEL", cpel.router),
    ("Actas", actas.router),
    ("Diplomado Sheets", diplomado_sheets.router),
]

for router_name, router_obj in routers_to_load:
    try:
        app.include_router(router_obj)
        logger.info(f"[OK] {router_name} router loaded")
    except Exception as e:
        logger.error(f"[ERROR] {router_name} router failed: {e}", exc_info=True)


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(
        "app.main:app",
        host="127.0.0.1",
        port=settings.port,
        reload=settings.environment == "development",
    )
