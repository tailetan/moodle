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
 * Calendar export
 *
 * @package    core_calendar
 * @copyright  1999 onwards Martin Dougiamas (http://dougiamas.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);

require_once('../config.php');
require_once($CFG->dirroot.'/calendar/lib.php');
require_once($CFG->libdir.'/bennu/bennu.inc.php');

raise_memory_limit(MEMORY_HUGE);

$userid = optional_param('userid', 0, PARAM_INT);
$username = optional_param('username', '', PARAM_TEXT);
$authtoken = required_param('authtoken', PARAM_ALPHANUM);
$generateurl = optional_param('generateurl', '', PARAM_TEXT);

if (empty($CFG->enablecalendarexport)) {
    die('no export');
}

$checkuserid = !empty($userid) && $user = \core_user::get_user($userid);
// Allowing for fallback check of old url - MDL-27542.
$checkusername = !empty($username) && $user = \core_user::get_user_by_username($username);
if ((!$checkuserid && !$checkusername) || !$user) {
    //No such user
    die('Invalid authentication');
}

// Check authentication token.
$authuserid = !empty($userid) && $authtoken == calendar_get_export_token($user);
// Allowing for fallback check of old url - MDL-27542.
$authusername = !empty($username) && $authtoken == sha1($username . $user->password . $CFG->calendar_exportsalt);
if (!$authuserid && !$authusername) {
    die('Invalid authentication');
}

// Setup up the user including web access logging.
\core\session\manager::set_user($user);

$PAGE->set_context(context_system::instance());

// Get the calendar type we are using.
$calendartype = \core_calendar\type_factory::get_calendar_instance();

$what = optional_param('preset_what', 'all', PARAM_ALPHA);
$time = optional_param('preset_time', 'weeknow', PARAM_ALPHA);

$now = $calendartype->timestamp_to_date_array(time());

// Let's see if we have sufficient and correct data
$allowedwhat = ['all', 'user', 'groups', 'courses', 'categories'];
$allowedtime = ['weeknow', 'weeknext', 'monthnow', 'monthnext', 'recentupcoming', 'custom'];

if (!empty($generateurl)) {
    $authtoken = calendar_get_export_token($user);
    $params = array();
    $params['preset_what'] = $what;
    $params['preset_time'] = $time;
    $params['userid'] = $userid;
    $params['authtoken'] = $authtoken;
    $params['generateurl'] = true;

    $link = new moodle_url('/calendar/export.php', $params);
    redirect($link->out());
    die;
}
$paramcategory = false;
if(!empty($what) && !empty($time)) {
    if(in_array($what, $allowedwhat) && in_array($time, $allowedtime)) {
        $courses = enrol_get_users_courses($user->id, true, 'id, visible, shortname');
        // Array of courses that we will pass to calendar_get_legacy_events() which
        // is initially set to the list of the user's courses.
        $paramcourses = $courses;
        if ($what == 'all' || $what == 'groups') {
            $groups = array();
            foreach ($courses as $course) {
                $course_groups = groups_get_all_groups($course->id, $user->id);
                $groups = array_merge($groups, array_keys($course_groups));
            }
            if (empty($groups)) {
                $groups = false;
            }
        }
        if ($what == 'all') {
            $users = $user->id;
            $courses[SITEID] = new stdClass;
            $courses[SITEID]->shortname = get_string('siteevents', 'calendar');
            $paramcourses[SITEID] = $courses[SITEID];
            $paramcategory = true;
        } else if ($what == 'groups') {
            $users = false;
            $paramcourses = array();
        } else if ($what == 'user') {
            $users = $user->id;
            $groups = false;
            $paramcourses = array();
        } else if ($what == 'categories') {
            $users = $user->id;
            $groups = false;
            $paramcourses = array();
            $paramcategory = true;
        } else {
            $users = false;
            $groups = false;
        }

        // Store the number of days in the week.
        $numberofdaysinweek = $calendartype->get_num_weekdays();

        switch($time) {
            case 'weeknow':
                $startweekday = calendar_get_starting_weekday();
                $startmonthday = find_day_in_month($now['mday'] - ($numberofdaysinweek - 1), $startweekday, $now['mon'], $now['year']);
                $startmonth = $now['mon'];
                $startyear = $now['year'];
                if ($startmonthday > calendar_days_in_month($startmonth, $startyear)) {
                    [$startmonth, $startyear] = $calendartype->get_next_month($startyear, $startmonth);
                    $startmonthday = find_day_in_month(1, $startweekday, $startmonth, $startyear);
                }
                $gregoriandate = $calendartype->convert_to_gregorian($startyear, $startmonth, $startmonthday);
                $timestart = make_timestamp($gregoriandate['year'], $gregoriandate['month'], $gregoriandate['day'],
                    $gregoriandate['hour'], $gregoriandate['minute']);

                $endmonthday = $startmonthday + $numberofdaysinweek;
                $endmonth = $startmonth;
                $endyear = $startyear;
                if ($endmonthday > calendar_days_in_month($endmonth, $endyear)) {
                    [$endmonth, $endyear] = $calendartype->get_next_month($endyear, $endmonth);
                    $endmonthday = find_day_in_month(1, $startweekday, $endmonth, $endyear);
                }
                $gregoriandate = $calendartype->convert_to_gregorian($endyear, $endmonth, $endmonthday);
                $timeend = make_timestamp($gregoriandate['year'], $gregoriandate['month'], $gregoriandate['day'],
                    $gregoriandate['hour'], $gregoriandate['minute']);
            break;
            case 'weeknext':
                $startweekday = calendar_get_starting_weekday();
                $startmonthday = find_day_in_month($now['mday'] + 1, $startweekday, $now['mon'], $now['year']);
                $startmonth = $now['mon'];
                $startyear = $now['year'];
                if ($startmonthday > calendar_days_in_month($startmonth, $startyear)) {
                    [$startmonth, $startyear] = $calendartype->get_next_month($startyear, $startmonth);
                    $startmonthday = find_day_in_month(1, $startweekday, $startmonth, $startyear);
                }
                $gregoriandate = $calendartype->convert_to_gregorian($startyear, $startmonth, $startmonthday);
                $timestart = make_timestamp($gregoriandate['year'], $gregoriandate['month'], $gregoriandate['day'],
                    $gregoriandate['hour'], $gregoriandate['minute']);

                $endmonthday = $startmonthday + $numberofdaysinweek;
                $endmonth = $startmonth;
                $endyear = $startyear;
                if ($endmonthday > calendar_days_in_month($endmonth, $endyear)) {
                    [$endmonth, $endyear] = $calendartype->get_next_month($endyear, $endmonth);
                    $endmonthday = find_day_in_month(1, $startweekday, $endmonth, $endyear);
                }
                $gregoriandate = $calendartype->convert_to_gregorian($endyear, $endmonth, $endmonthday);
                $timeend = make_timestamp($gregoriandate['year'], $gregoriandate['month'], $gregoriandate['day'],
                    $gregoriandate['hour'], $gregoriandate['minute']);
            break;
            case 'monthnow':
                // Convert to gregorian.
                $gregoriandate = $calendartype->convert_to_gregorian($now['year'], $now['mon'], 1);

                $timestart = make_timestamp($gregoriandate['year'], $gregoriandate['month'], $gregoriandate['day'],
                    $gregoriandate['hour'], $gregoriandate['minute']);
                $timeend = $timestart + (calendar_days_in_month($now['mon'], $now['year']) * DAYSECS);
            break;
            case 'monthnext':
                // Get the next month for this calendar.
                [$nextmonth, $nextyear] = $calendartype->get_next_month($now['year'], $now['mon']);

                // Convert to gregorian.
                $gregoriandate = $calendartype->convert_to_gregorian($nextyear, $nextmonth, 1);

                // Create the timestamps.
                $timestart = make_timestamp($gregoriandate['year'], $gregoriandate['month'], $gregoriandate['day'],
                    $gregoriandate['hour'], $gregoriandate['minute']);
                $timeend = $timestart + (calendar_days_in_month($nextmonth, $nextyear) * DAYSECS);
            break;
            case 'recentupcoming':
                //Events in the last 5 or next 60 days
                $timestart = time() - 432000;
                $timeend = time() + 5184000;
            break;
            case 'custom':
                // Events based on custom date range.
                $timestart = time() - $CFG->calendar_exportlookback * DAYSECS;
                $timeend = time() + $CFG->calendar_exportlookahead * DAYSECS;
            break;
        }
    }
    else {
        // Parameters given but incorrect, redirect back to export page
        redirect($CFG->wwwroot.'/calendar/export.php');
        die();
    }
}
$limitnum = 0;
$events = calendar_get_legacy_events($timestart, $timeend, $users, $groups, array_keys($paramcourses), false, true,
        $paramcategory, $limitnum);

$ical = new iCalendar;
$ical->add_property('method', 'PUBLISH');
$ical->add_property('prodid', '-//Moodle Pty Ltd//NONSGML Moodle Version ' . $CFG->version . '//EN');
foreach($events as $event) {
    if (!empty($event->modulename)) {
        $instances = get_fast_modinfo($event->courseid, $userid)->get_instances_of($event->modulename);
        if (empty($instances[$event->instance]->uservisible)) {
            continue;
        }
    }
    $hostaddress = str_replace('http://', '', $CFG->wwwroot);
    $hostaddress = str_replace('https://', '', $hostaddress);

    $me = new calendar_event($event); // To use moodle calendar event services.
    $ev = new iCalendar_event; // To export in ical format.
    $ev->add_property('uid', $event->id.'@'.$hostaddress);

    // Set iCal event summary from event name.
    $ev->add_property('summary', format_string($event->name, true, ['context' => $me->context]));

    // Format the description text.
    $description = format_text($me->description, $me->format, ['context' => $me->context]);
    // Then convert it to plain text, since it's the only format allowed for the event description property.
    // We use html_to_text in order to convert <br> and <p> tags to new line characters for descriptions in HTML format.
    $description = html_to_text($description, 0);
    $ev->add_property('description', $description);

    $ev->add_property('class', 'PUBLIC'); // PUBLIC / PRIVATE / CONFIDENTIAL
    $ev->add_property('last-modified', Bennu::timestamp_to_datetime($event->timemodified));

    if (!empty($event->location)) {
        $ev->add_property('location', $event->location);
    }

    $ev->add_property('dtstamp', Bennu::timestamp_to_datetime()); // now
    if ($event->timeduration > 0) {
        //dtend is better than duration, because it works in Microsoft Outlook and works better in Korganizer
        $ev->add_property('dtstart', Bennu::timestamp_to_datetime($event->timestart)); // when event starts.
        $ev->add_property('dtend', Bennu::timestamp_to_datetime($event->timestart + $event->timeduration));
    } else if ($event->timeduration == 0) {
        // When no duration is present, the event is instantaneous event, ex - Due date of a module.
        // Moodle doesn't support all day events yet. See MDL-56227.
        $ev->add_property('dtstart', Bennu::timestamp_to_datetime($event->timestart));
        $ev->add_property('dtend', Bennu::timestamp_to_datetime($event->timestart));
    } else {
        // This can be used to represent all day events in future.
        throw new coding_exception("Negative duration is not supported yet.");
    }
    if ($event->courseid != 0) {
        $coursecontext = context_course::instance($event->courseid);
        $ev->add_property('categories', format_string($courses[$event->courseid]->shortname, true, array('context' => $coursecontext)));
    }
    $ical->add_component($ev);
}

$serialized = $ical->serialize();
if(empty($serialized)) {
    // TODO
    die('bad serialization');
}

$filename = 'icalexport.ics';

if (!defined('BEHAT_SITE_RUNNING')) {
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', time()) . ' GMT');
    header('Cache-Control: private, must-revalidate, pre-check=0, post-check=0, max-age=0');
    header('Expires: ' . gmdate('D, d M Y H:i:s', 0) . 'GMT');
    header('Pragma: no-cache');
    header('Accept-Ranges: none'); // Comment out if PDFs do not work...
    header('Content-disposition: attachment; filename=' . $filename);
    header('Content-length: ' . strlen($serialized));
    header('Content-type: text/calendar; charset=utf-8');
}

echo $serialized;
