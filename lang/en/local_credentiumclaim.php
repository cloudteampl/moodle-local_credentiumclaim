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
 * English language strings for local_credentiumclaim.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Credentium Claim';

// Capabilities.
$string['credentiumclaim:claim'] = 'Claim own Credentium credentials';
$string['credentiumclaim:viewreports'] = 'View Credentium Claim status reports';

// Settings.
$string['globalsettings'] = 'Credentium Claim settings';
$string['settings_desc'] = 'Configure how learners are notified about issued-but-unclaimed Credentium digital credentials. This plugin reads issuance records created by the Credentium (local_credentium) plugin and lets learners claim their credentials.';
$string['enabled'] = 'Enable Credentium Claim';
$string['enabled_help'] = 'When enabled, the plugin checks the claim status of issued credentials and shows learners a reminder to claim any credential that is still unclaimed.';
$string['apiurl'] = 'Credentium API URL';
$string['apiurl_help'] = 'Base URL of the Credentium issuer API used for status checks and claim links (for example https://issuer.credentium.com). This may differ from the URL configured in the Credentium issuing plugin.';
$string['apikey'] = 'Credentium API key';
$string['apikey_help'] = 'The organisation API key sent in the API-KEY header. Keys use the format public_id.secret. The key is stored on the server and never shown in page output.';
$string['showbanner'] = 'Show reminder banner';
$string['showbanner_help'] = 'When enabled, a dismissible banner is shown at the top of every page to learners who have an unclaimed credential. When disabled, learners can still claim credentials from the "My credentials" page.';
$string['debuglog'] = 'Enable debug logging';
$string['debuglog_help'] = 'When enabled, the plugin writes diagnostic messages to the server error log. Secrets (API key, claim URLs) are never logged.';

// Test connection.
$string['testconnection'] = 'Test connection';
$string['testconnection_heading'] = 'Credentium connection test';
$string['testconnection_disabled'] = 'Please save the API URL and API key before testing the connection.';
$string['testconnection_success'] = 'Connection successful. The Credentium API responded and the API key is valid.';
$string['testconnection_templatecount'] = 'The API returned {$a} credential template(s).';
$string['testconnection_fail'] = 'Connection failed. Please check the API URL and API key.';

// Report.
$string['report'] = 'Credentium Claim status';
$string['report_heading'] = 'Credentium Claim status report';
$string['report_intro'] = 'Overview of tracked credential claim statuses across all learners.';
$string['report_status'] = 'Status';
$string['report_count'] = 'Count';
$string['report_total'] = 'Total tracked credentials';
$string['report_lastsync'] = 'Most recent status check: {$a}';
$string['report_neversynced'] = 'Status has not been checked yet. The scheduled task runs every 15 minutes.';

// Banner.
$string['banner_title'] = 'You have a new digital credential to claim';
$string['banner_message'] = 'Credentium has issued you {$a} digital credential(s) that you have not claimed yet.';
$string['banner_cta'] = 'Claim now';
$string['banner_dismiss'] = 'Dismiss';

// My credentials page.
$string['mycredentials'] = 'My credentials';
$string['mycredentials_heading'] = 'My Credentium credentials';
$string['mycredentials_intro'] = 'These digital credentials have been issued to you. Click "Claim" to save a credential to your Credentium Wallet.';
$string['mycredentials_empty'] = 'You have no unclaimed credentials right now.';
$string['col_course'] = 'Course';
$string['col_status'] = 'Status';
$string['col_action'] = 'Action';
$string['claim'] = 'Claim';

// Claim flow.
$string['claim_heading'] = 'Claim your credential';
$string['claim_alreadyclaimed'] = 'You have already claimed this credential. It is available in your Credentium Wallet.';
$string['claim_notready'] = 'This credential is still being prepared. Please try again in a few minutes.';
$string['claim_error'] = 'We could not open the claim link right now. Please try again later.';
$string['claim_backtolist'] = 'Back to my credentials';

// Statuses.
$string['status_processing'] = 'Processing';
$string['status_issued'] = 'Ready to claim';
$string['status_claimed'] = 'Claimed';
$string['status_failed'] = 'Failed';
$string['status_unknown'] = 'Unknown';

// Navigation.
$string['nav_mycredentials'] = 'My credentials';

// Scheduled task.
$string['task_syncstatus'] = 'Synchronise Credentium credential claim statuses';

// Cache.
$string['cachedef_claimable'] = 'Per-user count of unclaimed Credentium credentials';

// Errors.
$string['error:notconfigured'] = 'Credentium Claim is not configured. Set the API URL and API key in the plugin settings.';
$string['error:invalidapiurl'] = 'The API URL is not a valid URL.';
$string['error:apinotconfigured'] = 'The Credentium API is not configured.';
$string['apierror'] = 'The Credentium API returned an error.';
$string['error:invalidjsonresponse'] = 'The Credentium API returned an invalid response.';
$string['error:credentialnotfound'] = 'The requested credential could not be found.';

// Privacy.
$string['privacy:metadata:local_credentiumclaim_status'] = 'Tracking information about issued Credentium credentials and whether the learner has claimed them.';
$string['privacy:metadata:local_credentiumclaim_status:userid'] = 'The ID of the user the credential belongs to.';
$string['privacy:metadata:local_credentiumclaim_status:credentialkey'] = 'The Credentium issue-request identifier for the credential.';
$string['privacy:metadata:local_credentiumclaim_status:courseid'] = 'The ID of the course the credential was issued for.';
$string['privacy:metadata:local_credentiumclaim_status:remotestatus'] = 'The last known claim status of the credential.';
$string['privacy:metadata:local_credentiumclaim_status:timecreated'] = 'The time the tracking row was created.';
$string['privacy:metadata:credentium_api'] = 'To fetch claim status and generate a claim link, credential identifiers are sent to the Credentium API (a third-party commercial service).';
$string['privacy:metadata:credentium_api:issuerequestid'] = 'The Credentium issue-request identifier for the credential being checked or claimed.';
$string['privacy:metadata:credentium_api:locale'] = 'The language in which the claim experience should be presented.';
