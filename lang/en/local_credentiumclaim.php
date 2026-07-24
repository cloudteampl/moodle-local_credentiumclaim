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

$string['apierror'] = 'The Credentium® API returned an error.';
$string['banner_cta'] = 'Claim now';
$string['banner_dismiss'] = 'Dismiss';
$string['banner_message'] = 'Credentium® has issued you {$a} digital credential(s) that you have not claimed yet.';
$string['banner_title'] = 'You have a new digital credential to claim';
$string['cachedef_claimable'] = 'Per-user count of unclaimed Credentium® credentials';
$string['claim'] = 'Claim';
$string['claim_alreadyclaimed'] = 'You have already claimed this credential. It is available in your Credentium® Wallet.';
$string['claim_backtolist'] = 'Back to my credentials';
$string['claim_error'] = 'We could not open the claim link right now. Please try again later.';
$string['claim_heading'] = 'Claim your credential';
$string['claim_notready'] = 'This credential is still being prepared. Please try again in a few minutes.';
$string['col_action'] = 'Action';
$string['col_course'] = 'Course';
$string['col_status'] = 'Status';
$string['connection'] = 'API connection';
$string['connection_apikey'] = 'API key';
$string['connection_apikey_set'] = 'Configured';
$string['connection_apiurl'] = 'API URL';
$string['connection_help'] = 'This plugin does not store its own API credentials. It reuses the endpoint and key configured in the Credentium® Integration plugin, so a key only ever has to be rotated in one place. When that plugin runs in category mode, each credential is checked with the credentials that apply to its course.';
$string['connection_manage'] = 'Manage in the Credentium® Integration settings';
$string['connection_missingplugin'] = 'The Credentium® Integration plugin (local_credentium) is not installed. This plugin cannot work without it.';
$string['connection_notconfigured'] = 'No API URL and key have been configured in the Credentium® Integration plugin yet. Status checks and claim links will not work until they are.';
$string['credentiumclaim:claim'] = 'Claim own Credentium® credentials';
$string['credentiumclaim:viewreports'] = 'View Credentium® Claim status reports';
$string['debuglog'] = 'Enable debug logging';
$string['debuglog_help'] = 'When enabled, the plugin emits diagnostic messages through the Moodle developer debugging channel (shown when developer debugging is on). Secrets (API key, claim URLs) are never logged.';
$string['enabled'] = 'Enable Credentium® Claim';
$string['enabled_help'] = 'When enabled, the plugin checks the claim status of issued credentials and shows learners a reminder to claim any credential that is still unclaimed.';
$string['error:apinotconfigured'] = 'The Credentium® API is not configured.';
$string['error:credentialnotfound'] = 'The requested credential could not be found.';
$string['error:invalidapiurl'] = 'The API URL is not a valid URL.';
$string['error:invalidjsonresponse'] = 'The Credentium® API returned an invalid response.';
$string['error:notconfigured'] = 'Credentium® Claim is not configured. Check the API connection in the plugin settings.';
$string['globalsettings'] = 'Credentium® Claim settings';
$string['message_ready_body'] = 'Credentium® has issued you a digital credential that is ready to claim. Open "My credentials" to save it to your Credentium® Wallet.';
$string['message_ready_body_course'] = 'Credentium® has issued you a digital credential for "{$a->course}" that is ready to claim. Open "My credentials" to save it to your Credentium® Wallet.';
$string['message_ready_linktext'] = 'Go to My credentials';
$string['message_ready_small'] = 'You have a Credentium® credential ready to claim.';
$string['message_ready_subject'] = 'You have a credential to claim';
$string['messageprovider:credentialready'] = 'A digital credential is ready to claim';
$string['mycredentials'] = 'My credentials';
$string['mycredentials_empty'] = 'You have no unclaimed credentials right now.';
$string['mycredentials_heading'] = 'My Credentium® credentials';
$string['mycredentials_intro'] = 'These digital credentials have been issued to you. Click "Claim" to save a credential to your Credentium® Wallet.';
$string['nav_mycredentials'] = 'My credentials';
$string['nav_mycredentials_count'] = 'My credentials ({$a})';
$string['pluginname'] = 'Credentium® Claim';
$string['privacy:metadata:credentium_api'] = 'To fetch claim status and generate a claim link, credential identifiers are sent to the Credentium® API (a third-party commercial service).';
$string['privacy:metadata:credentium_api:issuerequestid'] = 'The Credentium® issue-request identifier for the credential being checked or claimed.';
$string['privacy:metadata:credentium_api:locale'] = 'The language in which the claim experience should be presented.';
$string['privacy:metadata:local_credentiumclaim_status'] = 'Tracking information about issued Credentium® credentials and whether the learner has claimed them.';
$string['privacy:metadata:local_credentiumclaim_status:courseid'] = 'The ID of the course the credential was issued for.';
$string['privacy:metadata:local_credentiumclaim_status:credentialkey'] = 'The Credentium® issue-request identifier for the credential.';
$string['privacy:metadata:local_credentiumclaim_status:remotestatus'] = 'The last known claim status of the credential.';
$string['privacy:metadata:local_credentiumclaim_status:timecreated'] = 'The time the tracking row was created.';
$string['privacy:metadata:local_credentiumclaim_status:userid'] = 'The ID of the user the credential belongs to.';
$string['report'] = 'Credentium® Claim status';
$string['report_checknow'] = 'Check status now';
$string['report_checknow_busy'] = 'A status check is already running (started by cron or another administrator). Try again in a moment.';
$string['report_checknow_failed'] = 'The status check could not be completed. Turn on debugging to see the reason, or check the last run outcome below.';
$string['report_connection_missing'] = 'No API credentials are available from the Credentium® Integration plugin, so statuses cannot be checked.';
$string['report_connection_ok'] = 'Inherited from Credentium® Integration ({$a})';
$string['report_count'] = 'Count';
$string['report_diagnostics'] = 'Diagnostics';
$string['report_error_auth'] = 'Credentium® refused the API key. In the Credentium® Integration plugin, check that the key is still valid and that it carries the "credentials:read" scope — a key that may only issue credentials cannot read their status.';
$string['report_error_client'] = 'Credentium® rejected the request itself. This normally means the plugin and the Credentium® API are out of step: check whether a plugin update is available, and quote the technical detail above if you contact support.';
$string['report_error_generic'] = 'The last status check failed. Quote the technical detail above if you contact support.';
$string['report_error_network'] = 'The Credentium® API could not be reached at all. Check that this server can make outbound HTTPS requests to the configured address — a proxy, a firewall rule or DNS is the usual cause.';
$string['report_error_pending'] = '{$a} tracked credential(s) could not be checked on this run; they keep their last known status and are re-checked automatically.';
$string['report_error_server'] = 'Credentium® accepted the request but could not complete it. This is a fault on the Credentium® side, not in this site\'s configuration. The plugin already retried, and will try again at the next scheduled check — statuses catch up on their own once the service recovers. If it keeps failing, contact Credentium® support and quote the technical detail above.';
$string['report_heading'] = 'Credentium® Claim status report';
$string['report_intro'] = 'Overview of tracked credential claim statuses across all learners.';
$string['report_lastresult'] = 'Last run outcome';
$string['report_lastrun'] = 'Last sync run';
$string['report_lastsync'] = 'Most recent credential check';
$string['report_never'] = 'Never';
$string['report_notified'] = 'Notifications sent on the last run';
$string['report_notifyfailed'] = 'Could not send {$a} "ready to claim" notification(s) on the last run (a learner may be suspended, or a delivery channel may have failed). Those learners still see the reminder in the user menu and banner when they log in.';
$string['report_plugindisabled'] = 'Credentium® Claim is disabled, so no status checks are being made.';
$string['report_property'] = 'Property';
$string['report_result_disabled'] = 'Skipped: the plugin is disabled.';
$string['report_result_error'] = 'Failed: {$a}';
$string['report_result_notconfigured'] = 'Skipped: no API credentials could be inherited from the Credentium® Integration plugin.';
$string['report_result_ok'] = 'Completed: {$a->polled} credential(s) checked, {$a->updated} updated.';
$string['report_schedule_custom'] = 'Custom schedule (edited under Server > Scheduled tasks)';
$string['report_schedule_every'] = 'Every {$a}';
$string['report_status'] = 'Status';
$string['report_tasklastrun'] = 'Scheduled task last ran';
$string['report_tasknextrun'] = 'Scheduled task next runs';
$string['report_total'] = 'Total tracked credentials';
$string['report_unmatched'] = 'Credentium® did not recognise {$a} tracked credential(s). This usually means the API key belongs to a different organisation than the one that issued them.';
$string['report_unresolved'] = '{$a} tracked credential(s) were skipped because no API credentials apply to their course.';
$string['report_value'] = 'Value';
$string['settings_desc'] = 'Configure how learners are notified about issued-but-unclaimed Credentium® digital credentials. This plugin reads issuance records created by the Credentium® Integration (local_credentium) plugin and reuses its API connection.';
$string['showbanner'] = 'Show reminder banner';
$string['showbanner_help'] = 'When enabled, a dismissible banner is shown at the top of every page to learners who have an unclaimed credential. When disabled, learners can still claim credentials from the "My credentials" page.';
$string['status_claimed'] = 'Claimed';
$string['status_failed'] = 'Failed';
$string['status_issued'] = 'Ready to claim';
$string['status_processing'] = 'Processing';
$string['status_unknown'] = 'Unknown';
$string['syncinterval'] = 'Status check interval';
$string['syncinterval_custom'] = 'Custom (keep current schedule)';
$string['syncinterval_help'] = 'How often the scheduled task asks Credentium® whether issued credentials are ready to claim or have been claimed. Shorter intervals keep statuses fresher at the cost of more API calls, and are useful while testing. Changing this rewrites the schedule of the "Synchronise Credentium credential claim statuses" task, which can also be edited under Server > Scheduled tasks.';
$string['task_syncstatus'] = 'Synchronise Credentium credential claim statuses';
$string['testconnection'] = 'Test connection';
$string['testconnection_disabled'] = 'Configure the API URL and key in the Credentium® Integration plugin before testing the connection.';
$string['testconnection_fail'] = 'Connection failed. Check the API URL and key in the Credentium® Integration plugin.';
$string['testconnection_heading'] = 'Credentium® connection test';
$string['testconnection_readscope_error'] = 'Status-read access could not be verified right now. This may be a temporary problem rather than a configuration one. Details: {$a}';
$string['testconnection_readscope_fail'] = 'The API key was accepted but cannot read credential statuses. This plugin needs the credentials:read scope, which the issuing plugin does not require — add it to the key in Credentium®. Details: {$a}';
$string['testconnection_readscope_ok'] = 'The API key can read credential statuses (credentials:read scope present).';
$string['testconnection_success'] = 'Connection successful. The Credentium® API responded and the API key is valid.';
$string['testconnection_templatecount'] = 'The API returned {$a} credential template(s).';
