<?php

namespace A1comms\GaeSupportLaravel\Http\Controllers;

use Illuminate\Routing\Controller;
use A1comms\GaeSupportLaravel\Session\DatastoreSessionHandler;

/**
 * SessionGarbageCollectionController
 *
 * @uses     Controller
 *
 * @category  GaeSupportL5
 */
class SessionGarbageCollectionController extends Controller
{
    /**
     * run
     *
     * @access public
     *
     * @return void
     */
    public function run()
    {
        $s = new DatastoreSessionHandler();
        $s->googlegc();
    }
}
