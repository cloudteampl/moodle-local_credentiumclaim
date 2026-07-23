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

$string['apierror'] = 'API Credentium® zwróciło błąd.';
$string['banner_cta'] = 'Odbierz teraz';
$string['banner_dismiss'] = 'Odrzuć';
$string['banner_message'] = 'Credentium® wystawił Ci {$a} poświadczenie(-a) cyfrowe, których jeszcze nie odebrano.';
$string['banner_title'] = 'Masz nowe poświadczenie cyfrowe do odebrania';
$string['cachedef_claimable'] = 'Liczba nieodebranych poświadczeń Credentium® na użytkownika';
$string['claim'] = 'Odbierz';
$string['claim_alreadyclaimed'] = 'To poświadczenie zostało już przez Ciebie odebrane. Jest dostępne w Twoim Portfelu Credentium®.';
$string['claim_backtolist'] = 'Powrót do moich poświadczeń';
$string['claim_error'] = 'Nie udało się teraz otworzyć linku do odbioru. Spróbuj ponownie później.';
$string['claim_heading'] = 'Odbierz swoje poświadczenie';
$string['claim_notready'] = 'To poświadczenie jest jeszcze przygotowywane. Spróbuj ponownie za kilka minut.';
$string['col_action'] = 'Akcja';
$string['col_course'] = 'Kurs';
$string['col_status'] = 'Status';
$string['connection'] = 'Połączenie z API';
$string['connection_apikey'] = 'Klucz API';
$string['connection_apikey_set'] = 'Skonfigurowany';
$string['connection_apiurl'] = 'Adres URL API';
$string['connection_help'] = 'Ta wtyczka nie przechowuje własnych poświadczeń API. Korzysta z adresu i klucza skonfigurowanych we wtyczce Integracja Credentium®, dzięki czemu klucz wystarczy rotować w jednym miejscu. Gdy tamta wtyczka działa w trybie kategorii, każde poświadczenie jest sprawdzane przy użyciu poświadczeń właściwych dla jego kursu.';
$string['connection_manage'] = 'Zarządzaj w ustawieniach wtyczki Integracja Credentium®';
$string['connection_missingplugin'] = 'Wtyczka Integracja Credentium® (local_credentium) nie jest zainstalowana. Bez niej ta wtyczka nie może działać.';
$string['connection_notconfigured'] = 'We wtyczce Integracja Credentium® nie skonfigurowano jeszcze adresu URL API ani klucza. Do tego czasu sprawdzanie statusów i linki do odbioru nie będą działać.';
$string['credentiumclaim:claim'] = 'Odbieranie własnych poświadczeń Credentium®';
$string['credentiumclaim:viewreports'] = 'Przeglądanie raportów statusu Credentium® Claim';
$string['debuglog'] = 'Włącz logowanie diagnostyczne';
$string['debuglog_help'] = 'Po włączeniu wtyczka emituje komunikaty diagnostyczne przez kanał debugowania deweloperskiego Moodle (widoczne, gdy włączone jest debugowanie deweloperskie). Sekrety (klucz API, adresy do odbioru) nigdy nie są logowane.';
$string['enabled'] = 'Włącz Credentium® Claim';
$string['enabled_help'] = 'Po włączeniu wtyczka sprawdza status odebrania wystawionych poświadczeń i wyświetla uczestnikom przypomnienie o odebraniu każdego poświadczenia, które nie zostało jeszcze odebrane.';
$string['error:apinotconfigured'] = 'API Credentium® nie jest skonfigurowane.';
$string['error:credentialnotfound'] = 'Nie znaleziono żądanego poświadczenia.';
$string['error:invalidapiurl'] = 'Adres URL API nie jest prawidłowym adresem URL.';
$string['error:invalidjsonresponse'] = 'API Credentium® zwróciło nieprawidłową odpowiedź.';
$string['error:notconfigured'] = 'Credentium® Claim nie jest skonfigurowany. Sprawdź połączenie z API w ustawieniach wtyczki.';
$string['globalsettings'] = 'Ustawienia Credentium® Claim';
$string['mycredentials'] = 'Moje poświadczenia';
$string['mycredentials_empty'] = 'Nie masz obecnie żadnych nieodebranych poświadczeń.';
$string['mycredentials_heading'] = 'Moje poświadczenia Credentium®';
$string['mycredentials_intro'] = 'Te poświadczenia cyfrowe zostały Ci wystawione. Kliknij „Odbierz”, aby zapisać poświadczenie w swoim Portfelu Credentium®.';
$string['nav_mycredentials'] = 'Moje poświadczenia';
$string['pluginname'] = 'Credentium® Claim';
$string['privacy:metadata:credentium_api'] = 'W celu sprawdzenia statusu odbioru i wygenerowania linku do odbioru identyfikatory poświadczeń są wysyłane do API Credentium® (zewnętrznej usługi komercyjnej).';
$string['privacy:metadata:credentium_api:issuerequestid'] = 'Identyfikator żądania wystawienia (issueRequestId) Credentium® sprawdzanego lub odbieranego poświadczenia.';
$string['privacy:metadata:credentium_api:locale'] = 'Język, w którym ma być prezentowany proces odbioru.';
$string['privacy:metadata:local_credentiumclaim_status'] = 'Informacje śledzące o wystawionych poświadczeniach Credentium® oraz o tym, czy uczestnik je odebrał.';
$string['privacy:metadata:local_credentiumclaim_status:courseid'] = 'Identyfikator kursu, dla którego wystawiono poświadczenie.';
$string['privacy:metadata:local_credentiumclaim_status:credentialkey'] = 'Identyfikator żądania wystawienia (issueRequestId) Credentium® dla poświadczenia.';
$string['privacy:metadata:local_credentiumclaim_status:remotestatus'] = 'Ostatni znany status odbioru poświadczenia.';
$string['privacy:metadata:local_credentiumclaim_status:timecreated'] = 'Czas utworzenia wiersza śledzenia.';
$string['privacy:metadata:local_credentiumclaim_status:userid'] = 'Identyfikator użytkownika, do którego należy poświadczenie.';
$string['report'] = 'Status Credentium® Claim';
$string['report_checknow'] = 'Sprawdź status teraz';
$string['report_checknow_failed'] = 'Nie udało się przeprowadzić sprawdzenia statusu. Szczegóły znajdziesz w logach serwera.';
$string['report_connection_missing'] = 'Wtyczka Integracja Credentium® nie udostępnia poświadczeń API, więc statusy nie mogą być sprawdzane.';
$string['report_connection_ok'] = 'Odziedziczone z wtyczki Integracja Credentium® ({$a})';
$string['report_count'] = 'Liczba';
$string['report_diagnostics'] = 'Diagnostyka';
$string['report_heading'] = 'Raport statusu Credentium® Claim';
$string['report_intro'] = 'Przegląd śledzonych statusów odbioru poświadczeń wszystkich uczestników.';
$string['report_lastresult'] = 'Wynik ostatniego uruchomienia';
$string['report_lastrun'] = 'Ostatnie uruchomienie synchronizacji';
$string['report_lastsync'] = 'Ostatnie sprawdzenie poświadczenia';
$string['report_never'] = 'Nigdy';
$string['report_plugindisabled'] = 'Credentium® Claim jest wyłączony, więc statusy nie są sprawdzane.';
$string['report_property'] = 'Właściwość';
$string['report_result_disabled'] = 'Pominięto: wtyczka jest wyłączona.';
$string['report_result_error'] = 'Niepowodzenie: {$a}';
$string['report_result_notconfigured'] = 'Pominięto: nie udało się odziedziczyć poświadczeń API z wtyczki Integracja Credentium®.';
$string['report_result_ok'] = 'Zakończono: sprawdzono poświadczeń: {$a->polled}, zaktualizowano: {$a->updated}.';
$string['report_schedule_custom'] = 'Harmonogram niestandardowy (edytowany w Serwer > Zaplanowane zadania)';
$string['report_schedule_every'] = 'Co {$a}';
$string['report_status'] = 'Status';
$string['report_tasklastrun'] = 'Ostatnie uruchomienie zaplanowanego zadania';
$string['report_tasknextrun'] = 'Następne uruchomienie zaplanowanego zadania';
$string['report_total'] = 'Łącznie śledzonych poświadczeń';
$string['report_unmatched'] = 'Credentium® nie rozpoznał {$a} śledzonych poświadczeń. Zwykle oznacza to, że klucz API należy do innej organizacji niż ta, która je wystawiła.';
$string['report_unresolved'] = 'Pominięto {$a} śledzonych poświadczeń, ponieważ dla ich kursu nie ma żadnych poświadczeń API.';
$string['report_value'] = 'Wartość';
$string['settings_desc'] = 'Skonfiguruj sposób informowania uczestników o wystawionych, lecz nieodebranych poświadczeniach cyfrowych Credentium®. Wtyczka odczytuje rekordy wystawień utworzone przez wtyczkę Integracja Credentium® (local_credentium) i korzysta z jej połączenia z API.';
$string['showbanner'] = 'Pokaż baner przypomnienia';
$string['showbanner_help'] = 'Po włączeniu na górze każdej strony wyświetlany jest zamykalny baner dla uczestników mających nieodebrane poświadczenie. Po wyłączeniu uczestnicy nadal mogą odbierać poświadczenia ze strony „Moje poświadczenia”.';
$string['status_claimed'] = 'Odebrane';
$string['status_failed'] = 'Niepowodzenie';
$string['status_issued'] = 'Gotowe do odbioru';
$string['status_processing'] = 'W przygotowaniu';
$string['status_unknown'] = 'Nieznany';
$string['syncinterval'] = 'Częstotliwość sprawdzania statusu';
$string['syncinterval_custom'] = 'Niestandardowa (zachowaj bieżący harmonogram)';
$string['syncinterval_help'] = 'Jak często zaplanowane zadanie pyta Credentium®, czy wystawione poświadczenia są gotowe do odbioru lub zostały odebrane. Krótsze odstępy oznaczają świeższe statusy kosztem większej liczby wywołań API i są przydatne podczas testów. Zmiana tej wartości przepisuje harmonogram zadania „Synchronizacja statusów odbioru poświadczeń Credentium”, który można też edytować w Serwer > Zaplanowane zadania.';
$string['task_syncstatus'] = 'Synchronizacja statusów odbioru poświadczeń Credentium';
$string['testconnection'] = 'Testuj połączenie';
$string['testconnection_disabled'] = 'Przed testem połączenia skonfiguruj adres URL API i klucz we wtyczce Integracja Credentium®.';
$string['testconnection_fail'] = 'Połączenie nieudane. Sprawdź adres URL API i klucz we wtyczce Integracja Credentium®.';
$string['testconnection_heading'] = 'Test połączenia z Credentium®';
$string['testconnection_success'] = 'Połączenie udane. API Credentium® odpowiedziało, a klucz API jest prawidłowy.';
$string['testconnection_templatecount'] = 'API zwróciło szablonów poświadczeń: {$a}.';
