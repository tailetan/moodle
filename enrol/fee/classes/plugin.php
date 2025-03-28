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
 * Fee enrolment plugin.
 *
 * This plugin allows you to set up paid courses.
 *
 * @package    enrol_fee
 * @copyright  2019 Shamim Rezaie <shamim@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\single_button;
use core_enrol\output\enrol_page;

/**
 * Fee enrolment plugin implementation.
 *
 * @copyright  2019 Shamim Rezaie <shamim@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_fee_plugin extends enrol_plugin {

    /**
     * How this enrolment method should be displayed on the "Enrolment methods" page
     *
     * @param stdClass $instance
     * @return string
     */
    public function get_instance_name_for_management_page(stdClass $instance): string {
        $result = $this->get_instance_name($instance);
        if (strlen((string)$instance->customchar1)) {
            $context = context_course::instance($instance->courseid);
            $result .= html_writer::empty_tag('br') .
                html_writer::tag('em', format_string($instance->customchar1, true, ['context' => $context]));
        }
        return $result;
    }

    /**
     * Returns the list of currencies that the payment subsystem supports and therefore we can work with.
     *
     * @return array[currencycode => currencyname]
     */
    public function get_possible_currencies(): array {
        $codes = \core_payment\helper::get_supported_currencies();

        $currencies = [];
        foreach ($codes as $c) {
            $currencies[$c] = new lang_string($c, 'core_currencies');
        }

        uasort($currencies, function($a, $b) {
            return strcmp($a, $b);
        });

        return $currencies;
    }

    /**
     * Returns optional enrolment information icons.
     *
     * This is used in course list for quick overview of enrolment options.
     *
     * We are not using single instance parameter because sometimes
     * we might want to prevent icon repetition when multiple instances
     * of one type exist. One instance may also produce several icons.
     *
     * @param array $instances all enrol instances of this type in one course
     * @return array of pix_icon
     */
    public function get_info_icons(array $instances) {
        $found = false;
        foreach ($instances as $instance) {
            if ($instance->enrolstartdate != 0 && $instance->enrolstartdate > time()) {
                continue;
            }
            if ($instance->enrolenddate != 0 && $instance->enrolenddate < time()) {
                continue;
            }
            $found = true;
            break;
        }
        if ($found) {
            return array(new pix_icon('icon', get_string('pluginname', 'enrol_fee'), 'enrol_fee'));
        }
        return array();
    }

    public function roles_protected() {
        // Users with role assign cap may tweak the roles later.
        return false;
    }

    public function allow_unenrol(stdClass $instance) {
        // Users with unenrol cap may unenrol other users manually - requires enrol/fee:unenrol.
        return true;
    }

    public function allow_manage(stdClass $instance) {
        // Users with manage cap may tweak period and status - requires enrol/fee:manage.
        return true;
    }

    public function show_enrolme_link(stdClass $instance) {
        return ($instance->status == ENROL_INSTANCE_ENABLED);
    }

    /**
     * Returns true if the user can add a new instance in this course.
     * @param int $courseid
     * @return boolean
     */
    public function can_add_instance($courseid) {
        $context = context_course::instance($courseid, MUST_EXIST);

        if (empty(\core_payment\helper::get_supported_currencies())) {
            return false;
        }

        if (!has_capability('moodle/course:enrolconfig', $context) or !has_capability('enrol/fee:config', $context)) {
            return false;
        }

        // Multiple instances supported - different cost for different roles.
        return true;
    }

    /**
     * We are a good plugin and don't invent our own UI/validation code path.
     *
     * @return boolean
     */
    public function use_standard_editing_ui() {
        return true;
    }

    /**
     * Add new instance of enrol plugin.
     * @param object $course
     * @param array $fields instance fields
     * @return int id of new instance, null if can not be created
     */
    public function add_instance($course, ?array $fields = null) {
        if ($fields && !empty($fields['cost'])) {
            $fields['cost'] = unformat_float($fields['cost']);
        }
        return parent::add_instance($course, $fields);
    }

    /**
     * Update instance of enrol plugin.
     * @param stdClass $instance
     * @param stdClass $data modified instance fields
     * @return boolean
     */
    public function update_instance($instance, $data) {
        if ($data) {
            $data->cost = unformat_float($data->cost);
        }
        return parent::update_instance($instance, $data);
    }

    #[\Override]
    public function enrol_page_hook(stdClass $instance) {
        global $USER, $OUTPUT, $DB, $PAGE;

        if ($DB->record_exists('user_enrolments', array('userid' => $USER->id, 'enrolid' => $instance->id))) {
            return '';
        }

        if ($instance->enrolstartdate != 0 && $instance->enrolstartdate > time()) {
            return '';
        }

        if ($instance->enrolenddate != 0 && $instance->enrolenddate < time()) {
            return '';
        }

        $course = $DB->get_record('course', array('id' => $instance->courseid));
        $context = context_course::instance($course->id);

        if ( (float) $instance->cost <= 0 ) {
            $cost = (float) $this->get_config('cost');
        } else {
            $cost = (float) $instance->cost;
        }

        $name = !empty($instance->name) ?
            format_string($instance->name, true, ['context' => $context]) :
            get_string('paymentrequired');

        if (abs($cost) < 0.01) { // No cost, other enrolment methods (instances) should be used.
            $notification = new \core\output\notification(get_string('nocost', 'enrol_fee'), 'error', false);
            $notification->set_extra_classes(['mb-0']);
            $enrolpage = new enrol_page(
                instance: $instance,
                header: $name,
                body: $OUTPUT->render($notification));
            return $OUTPUT->render($enrolpage);
        } else {
            if (isguestuser() || !isloggedin()) {
                $button = new single_button(new moodle_url(get_login_url()), get_string('loginsite'),
                     'get', single_button::BUTTON_PRIMARY);
            } else {
                $PAGE->requires->js_call_amd('core_payment/gateways_modal', 'init');
                $button = new single_button(
                    $PAGE->url,
                    get_string('sendpaymentbutton', 'enrol_fee'),
                    'post',
                    single_button::BUTTON_PRIMARY,
                    [
                        'data-action' => 'core_payment/triggerPayment',
                        'data-component' => 'enrol_fee',
                        'data-paymentarea' => 'fee',
                        'data-itemid' => $instance->id,
                        'data-cost' => $cost,
                        'data-successurl' => \enrol_fee\payment\service_provider::get_success_url('fee', $instance->id)->out(false),
                        'data-description' => get_string('purchasedescription', 'enrol_fee',
                            format_string($course->fullname, true, ['context' => $context])),
                    ]);
            }

            $body = $OUTPUT->render_from_template('enrol_fee/enrol_page', [
                'cost' => \core_payment\helper::get_cost_as_string($cost, $instance->currency),
            ]);
            $enrolpage = new enrol_page(
                instance: $instance,
                header: $name,
                body: $body,
                buttons: [$button]);
            return $OUTPUT->render($enrolpage);
        }
    }

    /**
     * Restore instance and map settings.
     *
     * @param restore_enrolments_structure_step $step
     * @param stdClass $data
     * @param stdClass $course
     * @param int $oldid
     */
    public function restore_instance(restore_enrolments_structure_step $step, stdClass $data, $course, $oldid) {
        global $DB;
        if ($step->get_task()->get_target() == backup::TARGET_NEW_COURSE) {
            $merge = false;
        } else {
            $merge = array(
                'courseid'   => $data->courseid,
                'enrol'      => $this->get_name(),
                'roleid'     => $data->roleid,
                'cost'       => $data->cost,
                'currency'   => $data->currency,
            );
        }
        if ($merge and $instances = $DB->get_records('enrol', $merge, 'id')) {
            $instance = reset($instances);
            $instanceid = $instance->id;
        } else {
            $instanceid = $this->add_instance($course, (array) $data);
        }
        $step->set_mapping('enrol', $oldid, $instanceid);
    }

    /**
     * Restore user enrolment.
     *
     * @param restore_enrolments_structure_step $step
     * @param stdClass $data
     * @param stdClass $instance
     * @param int $oldinstancestatus
     * @param int $userid
     */
    public function restore_user_enrolment(restore_enrolments_structure_step $step, $data, $instance, $userid, $oldinstancestatus) {
        $this->enrol_user($instance, $userid, null, $data->timestart, $data->timeend, $data->status);
    }

    /**
     * Return an array of valid options for the status.
     *
     * @return array
     */
    protected function get_status_options() {
        $options = array(ENROL_INSTANCE_ENABLED  => get_string('yes'),
                         ENROL_INSTANCE_DISABLED => get_string('no'));
        return $options;
    }

    /**
     * Return an array of valid options for the roleid.
     *
     * @param stdClass $instance
     * @param context $context
     * @return array
     */
    protected function get_roleid_options($instance, $context) {
        if ($instance->id) {
            $roles = get_default_enrol_roles($context, $instance->roleid);
        } else {
            $roles = get_default_enrol_roles($context, $this->get_config('roleid'));
        }
        return $roles;
    }


    /**
     * Add elements to the edit instance form.
     *
     * @param stdClass $instance
     * @param MoodleQuickForm $mform
     * @param context $context
     */
    public function edit_instance_form($instance, MoodleQuickForm $mform, $context) {

        $mform->addElement('text', 'name', get_string('custominstancename', 'enrol'));
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $mform->addElement('text', 'customchar1', get_string('instancedescription', 'enrol_fee'));
        $mform->setType('customchar1', PARAM_TEXT);
        $mform->addHelpButton('customchar1', 'instancedescription', 'enrol_fee');
        $mform->addRule('customchar1', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $options = $this->get_status_options();
        $mform->addElement('select', 'status', get_string('status', 'enrol_fee'), $options);
        $mform->setDefault('status', $this->get_config('status'));

        $accounts = \core_payment\helper::get_payment_accounts_menu($context);
        if ($accounts) {
            $accounts = ((count($accounts) > 1) ? ['' => ''] : []) + $accounts;
            $mform->addElement('select', 'customint1', get_string('paymentaccount', 'payment'), $accounts);
        } else {
            $mform->addElement('static', 'customint1_text', get_string('paymentaccount', 'payment'),
                html_writer::span(get_string('noaccountsavilable', 'payment'), 'alert alert-danger'));
            $mform->addElement('hidden', 'customint1');
            $mform->setType('customint1', PARAM_INT);
        }
        $mform->addHelpButton('customint1', 'paymentaccount', 'enrol_fee');

        $mform->addElement('text', 'cost', get_string('cost', 'enrol_fee'), array('size' => 4));
        $mform->setType('cost', PARAM_RAW);
        $mform->setDefault('cost', format_float($this->get_config('cost'), 2, true));

        $supportedcurrencies = $this->get_possible_currencies();
        $mform->addElement('select', 'currency', get_string('currency', 'enrol_fee'), $supportedcurrencies);
        $mform->setDefault('currency', $this->get_config('currency'));

        $roles = $this->get_roleid_options($instance, $context);
        $mform->addElement('select', 'roleid', get_string('assignrole', 'enrol_fee'), $roles);
        $mform->setDefault('roleid', $this->get_config('roleid'));

        $options = array('optional' => true, 'defaultunit' => 86400);
        $mform->addElement('duration', 'enrolperiod', get_string('enrolperiod', 'enrol_fee'), $options);
        $mform->setDefault('enrolperiod', $this->get_config('enrolperiod'));
        $mform->addHelpButton('enrolperiod', 'enrolperiod', 'enrol_fee');

        $options = array('optional' => true);
        $mform->addElement('date_time_selector', 'enrolstartdate', get_string('enrolstartdate', 'enrol_fee'), $options);
        $mform->setDefault('enrolstartdate', 0);
        $mform->addHelpButton('enrolstartdate', 'enrolstartdate', 'enrol_fee');

        $options = array('optional' => true);
        $mform->addElement('date_time_selector', 'enrolenddate', get_string('enrolenddate', 'enrol_fee'), $options);
        $mform->setDefault('enrolenddate', 0);
        $mform->addHelpButton('enrolenddate', 'enrolenddate', 'enrol_fee');

        if (enrol_accessing_via_instance($instance)) {
            $warningtext = get_string('instanceeditselfwarningtext', 'core_enrol');
            $mform->addElement('static', 'selfwarn', get_string('instanceeditselfwarning', 'core_enrol'), $warningtext);
        }
    }

    /**
     * Perform custom validation of the data used to edit the instance.
     *
     * @param array $data array of ("fieldname"=>value) of submitted data
     * @param array $files array of uploaded files "element_name"=>tmp_file_path
     * @param object $instance The instance loaded from the DB
     * @param context $context The context of the instance we are editing
     * @return array of "element_name"=>"error_description" if there are errors,
     *         or an empty array if everything is OK.
     */
    public function edit_instance_validation($data, $files, $instance, $context) {
        $errors = array();

        if (!empty($data['enrolenddate']) and $data['enrolenddate'] < $data['enrolstartdate']) {
            $errors['enrolenddate'] = get_string('enrolenddaterror', 'enrol_fee');
        }

        $cost = str_replace(get_string('decsep', 'langconfig'), '.', $data['cost']);
        if (!is_numeric($cost)) {
            $errors['cost'] = get_string('costerror', 'enrol_fee');
        }

        $validstatus = array_keys($this->get_status_options());
        $validcurrency = array_keys($this->get_possible_currencies());
        $validroles = array_keys($this->get_roleid_options($instance, $context));
        $tovalidate = array(
            'name' => PARAM_TEXT,
            'status' => $validstatus,
            'currency' => $validcurrency,
            'roleid' => $validroles,
            'enrolperiod' => PARAM_INT,
            'enrolstartdate' => PARAM_INT,
            'enrolenddate' => PARAM_INT
        );

        $typeerrors = $this->validate_param_types($data, $tovalidate);
        $errors = array_merge($errors, $typeerrors);

        if ($data['status'] == ENROL_INSTANCE_ENABLED &&
                (!$data['customint1']
                    || !array_key_exists($data['customint1'], \core_payment\helper::get_payment_accounts_menu($context)))) {
            $errors['status'] = 'Enrolments can not be enabled without specifying the payment account';
        }

        return $errors;
    }

    /**
     * Execute synchronisation.
     * @param progress_trace $trace
     * @return int exit code, 0 means ok
     */
    public function sync(progress_trace $trace) {
        $this->process_expirations($trace);
        return 0;
    }

    /**
     * Is it possible to delete enrol instance via standard UI?
     *
     * @param stdClass $instance
     * @return bool
     */
    public function can_delete_instance($instance) {
        $context = context_course::instance($instance->courseid);
        return has_capability('enrol/fee:config', $context);
    }

    /**
     * Is it possible to hide/show enrol instance via standard UI?
     *
     * @param stdClass $instance
     * @return bool
     */
    public function can_hide_show_instance($instance) {
        $context = context_course::instance($instance->courseid);
        return has_capability('enrol/fee:config', $context);
    }
}
