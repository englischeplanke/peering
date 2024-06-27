<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Definition of log events
 *
 * @package    mod_peering
 * @category   log
 * @copyright  2010 Petr Skoda (http://skodak.org)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$logs = array(
    // peering instance log actions
    array('module'=>'peering', 'action'=>'add', 'mtable'=>'peering', 'field'=>'name'),
    array('module'=>'peering', 'action'=>'update', 'mtable'=>'peering', 'field'=>'name'),
    array('module'=>'peering', 'action'=>'view', 'mtable'=>'peering', 'field'=>'name'),
    array('module'=>'peering', 'action'=>'view all', 'mtable'=>'peering', 'field'=>'name'),
    // submission log actions
    array('module'=>'peering', 'action'=>'add submission', 'mtable'=>'peering_submissions', 'field'=>'title'),
    array('module'=>'peering', 'action'=>'update submission', 'mtable'=>'peering_submissions', 'field'=>'title'),
    array('module'=>'peering', 'action'=>'view submission', 'mtable'=>'peering_submissions', 'field'=>'title'),
    // assessment log actions
    array('module'=>'peering', 'action'=>'add assessment', 'mtable'=>'peering_submissions', 'field'=>'title'),
    array('module'=>'peering', 'action'=>'update assessment', 'mtable'=>'peering_submissions', 'field'=>'title'),
    // example log actions
    array('module'=>'peering', 'action'=>'add example', 'mtable'=>'peering_submissions', 'field'=>'title'),
    array('module'=>'peering', 'action'=>'update example', 'mtable'=>'peering_submissions', 'field'=>'title'),
    array('module'=>'peering', 'action'=>'view example', 'mtable'=>'peering_submissions', 'field'=>'title'),
    // example assessment log actions
    array('module'=>'peering', 'action'=>'add reference assessment', 'mtable'=>'peering_submissions', 'field'=>'title'),
    array('module'=>'peering', 'action'=>'update reference assessment', 'mtable'=>'peering_submissions', 'field'=>'title'),
    array('module'=>'peering', 'action'=>'add example assessment', 'mtable'=>'peering_submissions', 'field'=>'title'),
    array('module'=>'peering', 'action'=>'update example assessment', 'mtable'=>'peering_submissions', 'field'=>'title'),
    // grading evaluation log actions
    array('module'=>'peering', 'action'=>'update aggregate grades', 'mtable'=>'peering', 'field'=>'name'),
    array('module'=>'peering', 'action'=>'update clear aggregated grades', 'mtable'=>'peering', 'field'=>'name'),
    array('module'=>'peering', 'action'=>'update clear assessments', 'mtable'=>'peering', 'field'=>'name'),
);
