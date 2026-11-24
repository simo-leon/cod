<?php

namespace Webkul\User;

class Bouncer
{
    /**
     * Checks if user allowed or not for certain action
     *
     * @param  string  $permission
     * @return void
     */
    public function hasPermission($permission)
    {
        // M4: an admin whose role was deleted has no permissions at
        // all — never read ->role->permission_type on null.
        if (
            auth()->guard('admin')->check()
            && auth()->guard('admin')->user()->role
            && auth()->guard('admin')->user()->role->permission_type == 'all'
        ) {
            return true;
        } else {
            if (
                ! auth()->guard('admin')->check()
                || ! auth()->guard('admin')->user()->hasPermission($permission)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks if user allowed or not for certain action
     *
     * @param  string  $permission
     * @return void
     */
    public static function allow($permission)
    {
        if (
            ! auth()->guard('admin')->check()
            || ! auth()->guard('admin')->user()->hasPermission($permission)
        ) {
            // M2: a logged-in admin without the permission is
            // forbidden (403), not unauthenticated (401) — 401 is for
            // guests, and the admin middleware already redirects those
            // to the login page.
            abort(403, 'This action is unauthorized');
        }
    }
}
