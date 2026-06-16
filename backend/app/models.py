from dataclasses import dataclass, field
from typing import Optional
from datetime import datetime


@dataclass
class Reply:
    author_name: str
    description: str
    basalam_answer_id: Optional[int] = None
    wc_comment_id: Optional[int] = None


@dataclass
class Review:
    basalam_review_id: int
    basalam_product_id: int
    product_title: str
    vendor_id: int
    user_name: str
    user_id: int
    star: int
    description: str
    created_at: str
    replies: list[Reply] = field(default_factory=list)
    wc_product_id: Optional[int] = None
    wc_comment_id: Optional[int] = None
    synced_at: Optional[str] = None
    hash: Optional[str] = None


@dataclass
class ProductMapping:
    basalam_product_id: int
    wc_product_id: int
    basalam_title: str
    wc_title: str


@dataclass
class SyncResult:
    run_at: str
    mode: str
    reviews_fetched: int = 0
    reviews_inserted: int = 0
    reviews_skipped: int = 0
    errors: int = 0
    error_messages: list[str] = field(default_factory=list)
