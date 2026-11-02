from __future__ import annotations
from typing import Literal, Optional
from pydantic import BaseModel, EmailStr, Field


# ── Users ─────────────────────────────────────────────────────────────────────

class TeamsUserCreate(BaseModel):
    display_name: str = Field(..., examples=["Juan García"])
    given_name: Optional[str] = None
    surname: Optional[str] = None
    user_principal_name: str = Field(..., examples=["jgarcia@tudominio.com"])
    mail_nickname: str = Field(..., examples=["jgarcia"])
    password: str = Field(..., min_length=8)
    role: Literal["student", "teacher"] = Field("student", description="Role used to assign the correct Teams license")
    department: Optional[str] = None
    job_title: Optional[str] = None
    usage_location: str = Field("ES", description="ISO 3166-1 alpha-2 country code")
    account_enabled: bool = True


class TeamsUserUpdate(BaseModel):
    display_name: Optional[str] = None
    given_name: Optional[str] = None
    surname: Optional[str] = None
    department: Optional[str] = None
    job_title: Optional[str] = None
    account_enabled: Optional[bool] = None
    mobile_phone: Optional[str] = None


# ── Teams / Groups ────────────────────────────────────────────────────────────

class TeamsTeamCreate(BaseModel):
    display_name: str = Field(..., examples=["Matemáticas I - 2025"])
    description: Optional[str] = None
    mail_nickname: str = Field(..., examples=["mat-i-2025"])
    email: Optional[EmailStr] = Field(None, description="Dirección de correo del grupo (ej: matematicas@usil.edu.py)")
    visibility: Literal["Public", "Private", "HiddenMembership"] = "Private"
    template: Literal["standard", "educationClass", "educationStaff", "educationProfessionalLearningCommunity"] = "educationClass"
    # At least one owner is required; additional owners and members can be provided
    owners: list[str] = Field(..., min_length=1, description="Azure object IDs of owners (at least 1)")
    members: list[str] = Field(default=[], description="Azure object IDs of regular members")


class TeamsTeamUpdate(BaseModel):
    display_name: Optional[str] = None
    description: Optional[str] = None
    visibility: Optional[Literal["Public", "Private"]] = None


# ── Members ───────────────────────────────────────────────────────────────────

MemberRole = Literal["member", "owner"]


class TeamsMemberAdd(BaseModel):
    user_id: str = Field(..., description="Azure AD object ID of the user")
    role: MemberRole = "member"


class TeamsMemberRemove(BaseModel):
    user_id: str = Field(..., description="Azure AD object ID of the user")


# ── Channels ──────────────────────────────────────────────────────────────────

class TeamsChannelCreate(BaseModel):
    display_name: str = Field(..., examples=["Anuncios del curso"])
    description: Optional[str] = None
    membership_type: Literal["standard", "private", "shared"] = "standard"


# ── Bulk helpers ─────────────────────────────────────────────────────────────

class BulkTeamsUserCreate(BaseModel):
    users: list[TeamsUserCreate]


class BulkTeamsMemberAdd(BaseModel):
    team_id: str
    members: list[TeamsMemberAdd]


class BulkTeamsMemberRemove(BaseModel):
    team_id: str
    user_ids: list[str]


class BulkResult(BaseModel):
    succeeded: list[dict] = []
    failed: list[dict] = []

class BulkTeamsEmailAdd(BaseModel):
    emails: list[str]
    role: MemberRole = "member"
