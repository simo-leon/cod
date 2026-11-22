<?php

namespace App\Cod\Products\Exceptions;

use RuntimeException;

/**
 * Thrown when a product image (uploaded file or remote URL) cannot be
 * used: unreadable file, failed download, wrong content type, or over
 * the size limit. Controllers translate this into a 422 response so a
 * bad image never creates a half-finished product.
 */
class ProductImageException extends RuntimeException {}
