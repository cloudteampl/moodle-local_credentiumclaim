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
 * Polish language strings for local_credentiumclaim.
 *
 * @package    local_credentiumclaim
 * @copyright  2025 CloudTeam Sp. z o.o.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Credentium Claim';

// Settings.
$string['globalsettings'] = 'Ustawienia Credentium Claim';
$string['settings_desc'] = 'Skonfiguruj sposób informowania uczestników o wystawionych, lecz nieodebranych poświadczeniach cyfrowych Credentium. Wtyczka odczytuje rekordy wystawień utworzone przez wtyczkę Credentium (local_credentium) i umożliwia uczestnikom odebranie ich poświadczeń.';
$string['enabled'] = 'Włącz Credentium Claim';
$string['enabled_help'] = 'Po włączeniu wtyczka sprawdza status odebrania wystawionych poświadczeń i wyświetla uczestnikom przypomnienie o odebraniu każdego poświadczenia, które nie zostało jeszcze odebrane.';
$string['apiurl'] = 'Adres URL API Credentium';
$string['apiurl_help'] = 'Bazowy adres URL API wystawcy Credentium używany do sprawdzania statusu i generowania linków do odbioru (np. https://issuer.credentium.com). Może różnić się od adresu skonfigurowanego we wtyczce wystawiającej.';
$string['apikey'] = 'Klucz API Credentium';
$string['apikey_help'] = 'Klucz API organizacji wysyłany w nagłówku API-KEY. Klucze mają format public_id.secret. Klucz jest przechowywany na serwerze i nigdy nie jest wyświetlany w treści strony.';
$string['showbanner'] = 'Pokaż baner przypomnienia';
$string['showbanner_help'] = 'Po włączeniu na górze każdej strony wyświetlany jest zamykalny baner dla uczestników mających nieodebrane poświadczenie. Po wyłączeniu uczestnicy nadal mogą odbierać poświadczenia ze strony „Moje poświadczenia”.';
$string['debuglog'] = 'Włącz logowanie diagnostyczne';
$string['debuglog_help'] = 'Po włączeniu wtyczka zapisuje komunikaty diagnostyczne do logu błędów serwera. Sekrety (klucz API, adresy do odbioru) nigdy nie są logowane.';

// Test connection.
$string['testconnection'] = 'Testuj połączenie';
$string['testconnection_heading'] = 'Test połączenia z Credentium';
$string['testconnection_disabled'] = 'Przed testem połączenia zapisz adres URL API oraz klucz API.';
$string['testconnection_success'] = 'Połączenie udane. API Credentium odpowiedziało, a klucz API jest prawidłowy.';
$string['testconnection_templatecount'] = 'API zwróciło szablonów poświadczeń: {$a}.';
$string['testconnection_fail'] = 'Połączenie nieudane. Sprawdź adres URL API oraz klucz API.';
$string['backtosettings'] = 'Powrót do ustawień';

// Report.
$string['report'] = 'Status Credentium Claim';
$string['report_heading'] = 'Raport statusu Credentium Claim';
$string['report_intro'] = 'Przegląd śledzonych statusów odbioru poświadczeń wszystkich uczestników.';
$string['report_status'] = 'Status';
$string['report_count'] = 'Liczba';
$string['report_total'] = 'Łącznie śledzonych poświadczeń';
$string['report_lastsync'] = 'Ostatnie sprawdzenie statusu: {$a}';
$string['report_neversynced'] = 'Status nie został jeszcze sprawdzony. Zaplanowane zadanie uruchamia się co 15 minut.';

// Banner.
$string['banner_title'] = 'Masz nowe poświadczenie cyfrowe do odebrania';
$string['banner_message'] = 'Credentium wystawił Ci {$a} poświadczenie(-a) cyfrowe, których jeszcze nie odebrano.';
$string['banner_cta'] = 'Odbierz teraz';
$string['banner_dismiss'] = 'Odrzuć';

// My credentials page.
$string['mycredentials'] = 'Moje poświadczenia';
$string['mycredentials_heading'] = 'Moje poświadczenia Credentium';
$string['mycredentials_intro'] = 'Te poświadczenia cyfrowe zostały Ci wystawione. Kliknij „Odbierz”, aby zapisać poświadczenie w swoim Portfelu Credentium.';
$string['mycredentials_empty'] = 'Nie masz obecnie żadnych nieodebranych poświadczeń.';
$string['col_course'] = 'Kurs';
$string['col_status'] = 'Status';
$string['col_action'] = 'Akcja';
$string['claim'] = 'Odbierz';
$string['dismiss'] = 'Odrzuć';

// Claim flow.
$string['claim_heading'] = 'Odbierz swoje poświadczenie';
$string['claim_opening'] = 'Otwieranie Credentium, abyś mógł(-mogła) odebrać swoje poświadczenie…';
$string['claim_manuallink'] = 'Jeśli nic się nie dzieje, użyj tego linku, aby odebrać poświadczenie.';
$string['claim_alreadyclaimed'] = 'To poświadczenie zostało już przez Ciebie odebrane. Jest dostępne w Twoim Portfelu Credentium.';
$string['claim_notready'] = 'To poświadczenie jest jeszcze przygotowywane. Spróbuj ponownie za kilka minut.';
$string['claim_paused'] = 'Odbieranie poświadczeń jest chwilowo niedostępne. Spróbuj ponownie później.';
$string['claim_error'] = 'Nie udało się teraz otworzyć linku do odbioru. Spróbuj ponownie później.';
$string['claim_backtolist'] = 'Powrót do moich poświadczeń';

// Statuses.
$string['status_processing'] = 'W przygotowaniu';
$string['status_issued'] = 'Gotowe do odbioru';
$string['status_claimed'] = 'Odebrane';
$string['status_failed'] = 'Niepowodzenie';
$string['status_unknown'] = 'Nieznany';

// Navigation.
$string['nav_mycredentials'] = 'Moje poświadczenia';

// Scheduled task.
$string['task_syncstatus'] = 'Synchronizacja statusów odbioru poświadczeń Credentium';

// Cache.
$string['cachedef_claimable'] = 'Liczba nieodebranych poświadczeń Credentium na użytkownika';

// Errors.
$string['error:notconfigured'] = 'Credentium Claim nie jest skonfigurowany. Ustaw adres URL API oraz klucz API w ustawieniach wtyczki.';
$string['error:invalidapiurl'] = 'Adres URL API nie jest prawidłowym adresem URL.';
$string['error:apinotconfigured'] = 'API Credentium nie jest skonfigurowane.';
$string['apierror'] = 'API Credentium zwróciło błąd.';
$string['error:invalidjsonresponse'] = 'API Credentium zwróciło nieprawidłową odpowiedź.';
$string['error:credentialnotfound'] = 'Nie znaleziono żądanego poświadczenia.';
$string['error:notyours'] = 'Nie masz uprawnień do odebrania tego poświadczenia.';

// Privacy.
$string['privacy:metadata:local_credentiumclaim_status'] = 'Informacje śledzące o wystawionych poświadczeniach Credentium oraz o tym, czy uczestnik je odebrał.';
$string['privacy:metadata:local_credentiumclaim_status:userid'] = 'Identyfikator użytkownika, do którego należy poświadczenie.';
$string['privacy:metadata:local_credentiumclaim_status:credentialkey'] = 'Identyfikator żądania wystawienia (issueRequestId) Credentium dla poświadczenia.';
$string['privacy:metadata:local_credentiumclaim_status:courseid'] = 'Identyfikator kursu, dla którego wystawiono poświadczenie.';
$string['privacy:metadata:local_credentiumclaim_status:remotestatus'] = 'Ostatni znany status odbioru poświadczenia.';
$string['privacy:metadata:local_credentiumclaim_status:timecreated'] = 'Czas utworzenia wiersza śledzenia.';
$string['privacy:metadata:credentium_api'] = 'W celu sprawdzenia statusu odbioru i wygenerowania linku do odbioru identyfikatory poświadczeń są wysyłane do API Credentium (zewnętrznej usługi komercyjnej).';
$string['privacy:metadata:credentium_api:issuerequestid'] = 'Identyfikator żądania wystawienia (issueRequestId) Credentium sprawdzanego lub odbieranego poświadczenia.';
$string['privacy:metadata:credentium_api:locale'] = 'Język, w którym ma być prezentowany proces odbioru.';
