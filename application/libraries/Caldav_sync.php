<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.5.0
 * ---------------------------------------------------------------------------- */

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Jsvrcek\ICS\Exception\CalendarEventException;
use Psr\Http\Message\ResponseInterface;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

/**
 * Caldav sync library.
 *
 * Handles CalDAV related operations using Guzzle.
 *
 * @package Libraries
 */
class Caldav_sync
{
    /**
     * @var EA_Controller|CI_Controller
     */
    protected EA_Controller|CI_Controller $CI;

    /**
     * Caldav_sync constructor.
     *
     * This method initializes the Caldav client class and the Calendar service class so that they can be used by the
     * other methods.
     *
     * @throws Exception If there is an issue with the initialization.
     */
    public function __construct()
    {
        $this->CI = &get_instance();

        $this->CI->load->model('appointments_model');
        $this->CI->load->model('customers_model');
        $this->CI->load->model('providers_model');
        $this->CI->load->model('services_model');

        $this->CI->load->library('ics_file');
    }

    /**
     * Add an appointment record to the connected CalDAV calendar.
     *
     * @param array $appointment Appointment record.
     * @param array $service Service record.
     * @param array $provider Provider record.
     * @param array $customer Customer record.
     *
     * @return string|null Returns the event ID
     *
     * @throws CalendarEventException If there's an issue generating the ICS file.
     */
    public function save_appointment(array $appointment, array $service, array $provider, array $customer): ?string
    {
        try {
            $ics_file = $this->get_appointment_ics_file($appointment, $service, $provider, $customer);

            $client = $this->get_http_client_by_provider_id($provider['id']);

            $caldav_event_id =
                $appointment['id_caldav_calendar'] ?: $this->CI->ics_file->generate_uid($appointment['id']);

            $uri = $this->get_caldav_event_uri($provider['settings']['caldav_url'], $caldav_event_id);

            $client->request('PUT', $uri, [
                'headers' => [
                    'Content-Type' => 'text/calendar',
                ],
                'body' => $ics_file,
            ]);

            return $caldav_event_id;
        } catch (GuzzleException $e) {
            $this->handle_guzzle_exception($e, 'Failed to save CalDAV appointment event');
            return null;
        }
    }

    /**
     * Add an unavailability record to the connected CalDAV calendar.
     *
     * @param array $unavailability Appointment record.
     * @param array $provider Provider record.
     *
     * @return string|null Returns the event ID
     *
     * @throws CalendarEventException If there's an issue generating the ICS file.
     */
    public function save_unavailability(array $unavailability, array $provider): ?string
    {
        try {
            if (str_contains((string) $unavailability['id_caldav_calendar'], 'RECURRENCE')) {
                return $unavailability['id_caldav_calendar'] ?? null; // Do not sync recurring unavailabilities
            }

            $ics_file = $this->get_unavailability_ics_file($unavailability, $provider);

            $client = $this->get_http_client_by_provider_id($provider['id']);

            $caldav_event_id =
                $unavailability['id_caldav_calendar'] ?: $this->CI->ics_file->generate_uid($unavailability['id']);

            $uri = $this->get_caldav_event_uri($provider['settings']['caldav_url'], $caldav_event_id);

            $client->request('PUT', $uri, [
                'headers' => [
                    'Content-Type' => 'text/calendar',
                ],
                'body' => $ics_file,
            ]);

            return $caldav_event_id;
        } catch (GuzzleException $e) {
            $this->handle_guzzle_exception($e, 'Failed to save CalDAV unavailability event');
            return null;
        }
    }

    /**
     * Delete an existing appointment from Caldav Calendar.
     *
     * @param array $provider Provider data.
     * @param string $caldav_event_id The Caldav Calendar event ID to be removed.
     */
    public function delete_event(array $provider, string $caldav_event_id): void
    {
        try {
            $client = $this->get_http_client_by_provider_id($provider['id']);

            $uri = $this->get_caldav_event_uri($provider['settings']['caldav_url'], $caldav_event_id);

            $client->request('DELETE', $uri);
        } catch (GuzzleException $e) {
            $this->handle_guzzle_exception($e, 'Failed to delete CalDAV event');
        }
    }

    /**
     * Get a Caldav Calendar event.
     *
     * @param array $provider Provider Data.
     * @param string $caldav_event_id CalDAV calendar event ID.
     *
     * @return array|null
     * @throws Exception If there’s an issue parsing the ICS data.
     */
    public function get_event(array $provider, string $caldav_event_id): ?array
    {
        try {
            $client = $this->get_http_client_by_provider_id($provider['id']);

            $provider_timezone_object = new DateTimeZone($provider['timezone']);

            $uri = $this->get_caldav_event_uri($provider['settings']['caldav_url'], $caldav_event_id);

            $response = $client->request('GET', $uri);

            $ics_file = $response->getBody()->getContents();

            $vcalendar = Reader::read($ics_file);

            return $this->convert_caldav_event_to_array_event($vcalendar->VEVENT, $provider_timezone_object);
        } catch (GuzzleException $e) {
            $this->handle_guzzle_exception($e, 'Failed to get CalDAV event');
            return null;
        }
    }

    /**
     * Get all the events between the sync period.
     *
     * @param array $provider Provider data.
     * @param string $start_date_time The start date of sync period.
     * @param string $end_date_time The end date of sync period.
     *
     * @return array
     * @throws Exception If there's an issue with event fetching or parsing.
     */
    public function get_sync_events(array $provider, string $start_date_time, string $end_date_time): array
    {
        try {
            $client = $this->get_http_client_by_provider_id($provider['id']);
            $provider_timezone_object = new DateTimeZone($provider['timezone']);
    
            $response = $this->fetch_events($client, $start_date_time, $end_date_time);
            $response_body = $response->getBody()->getContents();
            
            // Parse the XML response
            $xml = new SimpleXMLElement($response_body);
            $xml->registerXPathNamespace('d', 'DAV:');
            $xml->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');
            
            $events = [];
            
            // Find all calendar-data elements
            foreach ($xml->xpath('//c:calendar-data') as $calendar_data) {
                $ical_data = (string)$calendar_data;
                log_message('debug', 'Processing iCal data block');
                
                try {
                    // Parse the iCal data
                    $vcalendar = Reader::read($ical_data);
                    
                    // Process each VEVENT in the calendar
                    foreach ($vcalendar->VEVENT as $vevent) {
                        try {
                            // Get event start and end times
                            $start = $vevent->DTSTART->getDateTime();
                            $end = $vevent->DTEND->getDateTime();
                            
                            // Convert to provider's timezone
                            $start->setTimezone($provider_timezone_object);
                            $end->setTimezone($provider_timezone_object);
                            
                            $event = [
                                'id' => (string)$vevent->UID,
                                'start_datetime' => $start->format('Y-m-d H:i:s'),
                                'end_datetime' => $end->format('Y-m-d H:i:s'),
                                'summary' => (string)$vevent->SUMMARY ?? '',
                                'description' => (string)$vevent->DESCRIPTION ?? '',
                                'location' => (string)$vevent->LOCATION ?? '',
                                'status' => (string)$vevent->STATUS ?? 'CONFIRMED'
                            ];
                            
                            log_message('debug', 'Parsed event: ' . json_encode($event));
                            $events[] = $event;
                            
                        } catch (Exception $e) {
                            log_message('error', 'Error parsing individual event: ' . $e->getMessage());
                            continue; // Skip this event but continue with others
                        }
                    }
                } catch (Exception $e) {
                    log_message('error', 'Error parsing iCal data: ' . $e->getMessage());
                    continue; // Skip this calendar data but continue with others
                }
            }
            
            log_message('info', 'Successfully parsed ' . count($events) . ' events from CalDAV response');
            return $events;
            
        } catch (Exception $e) {
            log_message('error', 'Error in get_sync_events: ' . $e->getMessage());
            throw $e;
        }
    }
    

    /**
     * Common error handling for the CalDAV requests.
     *
     * @param GuzzleException $e
     * @param string $message
     *
     * @return void
     */
    private function handle_guzzle_exception(GuzzleException $e, string $message): void
    {
        // Guzzle throws a RequestException for HTTP errors

        if ($e instanceof RequestException && $e->hasResponse()) {
            // Handle HTTP error response

            $response = $e->getResponse();

            $status_code = $response->getStatusCode();

            $guzzle_info = '(Status Code: ' . $status_code . '): ' . $response->getBody()->getContents() . PHP_EOL;
        } else {
            // Handle other request errors

            $guzzle_info = $e->getMessage();
        }

        log_message('error', $message . ' ' . $guzzle_info);
    }

    /**
     * @throws Exception If there is an invalid CalDAV URL or credentials.
     * @throws GuzzleException If there’s an issue with the HTTP request.
     */
    private function get_http_client(string $caldav_url, string $caldav_username, string $caldav_password): Client
    {
        if (!filter_var($caldav_url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Invalid CalDAV URL provided: ' . $caldav_url);
        }

        if (!$caldav_username) {
            throw new InvalidArgumentException('Missing CalDAV username');
        }

        if (!$caldav_password) {
            throw new InvalidArgumentException('Missing CalDAV password');
        }

        return new Client([
            'base_uri' => rtrim($caldav_url, '/') . '/',
            'connect_timeout' => 15,
            'headers' => [
                'Content-Type' => 'text/xml',
            ],
            'auth' => [$caldav_username, $caldav_password],
        ]);
    }

    /**
     * @throws Exception
     * @throws GuzzleException
     */
    public function test_connection(string $caldav_url, string $caldav_username, string $caldav_password): void
    {
        try {
            // Fetch some events to see if the connection is valid
            $client = $this->get_http_client($caldav_url, $caldav_username, $caldav_password);

            $start_date_time = date('Y-m-d 00:00:00');
            $end_date_time = date('Y-m-d 23:59:59');

            $this->fetch_events($client, $start_date_time, $end_date_time);
        } catch (GuzzleException $e) {
            $this->handle_guzzle_exception($e, 'Failed to test CalDAV connection');
            throw $e;
        }
    }

    /**
     * @throws GuzzleException
     */
    private function get_http_client_by_provider_id(int $provider_id): Client
    {
        $provider = $this->CI->providers_model->find($provider_id);

        if (!$provider['settings']['caldav_sync']) {
            throw new RuntimeException('The selected provider does not have the CalDAV sync enabled: ' . $provider_id);
        }

        $caldav_url = $provider['settings']['caldav_url'];
        $caldav_username = $provider['settings']['caldav_username'];
        $caldav_password = $provider['settings']['caldav_password'];

        return $this->get_http_client($caldav_url, $caldav_username, $caldav_password);
    }

    /**
     * Generate the event URI, used in various requests.
     *
     * @param string $caldav_calendar
     * @param string|null $caldav_event_id
     *
     * @return string
     */
    private function get_caldav_event_uri(string $caldav_calendar, ?string $caldav_event_id = null): string
    {
        return $caldav_event_id ? rtrim($caldav_calendar, '/') . '/' . $caldav_event_id . '.ics' : '';
    }

    /**
     * @throws CalendarEventException
     */
    private function get_appointment_ics_file(
        array $appointment,
        array $service,
        array $provider,
        array $customer,
    ): string {
        $ics_file = $this->CI->ics_file->get_stream($appointment, $service, $provider, $customer);

        return str_replace('METHOD:PUBLISH', '', $ics_file);
    }

    /**
     * @throws CalendarEventException
     */
    private function get_unavailability_ics_file(array $unavailability, array $provider): string
    {
        $ics_file = $this->CI->ics_file->get_unavailability_stream($unavailability, $provider);

        return str_replace('METHOD:PUBLISH', '', $ics_file);
    }

    /**
     * Try to parse the CalDAV event date-time value with the right timezone.
     *
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     */
    private function parse_date_time_object(string $caldav_date_time, DateTimeZone $default_timezone_object): DateTime
    {
        try {
            if (str_contains($caldav_date_time, 'TZID=')) {
                // Extract the TZID and use it
                preg_match('/TZID=([^:]+):/', $caldav_date_time, $matches);
                $parsed_timezone = $matches[1];
                $parsed_timezone_object = new DateTimeZone($parsed_timezone);
                $date_time = preg_replace('/TZID=[^:]+:/', '', $caldav_date_time);
                $date_time_object = new DateTime($date_time, $parsed_timezone_object);
            } elseif (str_ends_with($caldav_date_time, 'Z')) {
                // Handle UTC timestamps
                $date_time_object = new DateTime($caldav_date_time, new DateTimeZone('UTC'));
            } else {
                // Default to the provided timezone
                $date_time_object = new DateTime($caldav_date_time, $default_timezone_object);
            }

            return $date_time_object;
        } catch (Throwable $e) {
            error_log('Error parsing date-time value (' . $caldav_date_time . ') with timezone: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Convert the VEvent object to an associative array
     *
     * @link https://sabre.io/vobject/icalendar
     *
     * @param VEvent $vevent Holds the VEVENT information
     * @param DateTimeZone $timezone_object The date timezone values
     *
     * @return array
     *
     * @throws Throwable
     */
    private function convert_caldav_event_to_array_event(VEvent $vevent, DateTimeZone $timezone_object): array
    {
        try {
            $caldav_start_date_time = (string) $vevent->DTSTART;
            $start_date_time_object = $this->parse_date_time_object($caldav_start_date_time, $timezone_object);
            $start_date_time_object->setTimezone($timezone_object); // Convert to the provider timezone

            $caldav_end_date_time = (string) $vevent->DTEND;
            $end_date_time_object = $this->parse_date_time_object($caldav_end_date_time, $timezone_object);
            $end_date_time_object->setTimezone($timezone_object); // Convert to the provider timezone

            // Check if the event is recurring

            $is_recurring_event =
                isset($vevent->RRULE) ||
                isset($vevent->RDATE) ||
                isset($vevent->{'RECURRENCE-ID'}) ||
                isset($vevent->EXDATE);

            // Generate ID based on recurrence status

            $event_id = (string) $vevent->UID;

            if ($is_recurring_event) {
                $event_id .= '-RECURRENCE-' . random_string();
            }

            // Return the converted event

            return [
                'id' => $event_id,
                'summary' => (string) $vevent->SUMMARY ?? null ?: '',
                'start_datetime' => $start_date_time_object->format('Y-m-d H:i:s'),
                'end_datetime' => $end_date_time_object->format('Y-m-d H:i:s'),
                'description' => (string) $vevent->DESCRIPTION ?? null ?: '',
                'status' => (string) $vevent->STATUS ?? null ?: 'CONFIRMED',
                'location' => (string) $vevent->LOCATION ?? null ?: '',
            ];
        } catch (Throwable $e) {
            error_log('Error parsing CalDAV event object (' . var_export($vevent, true) . '): ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    private function fetch_events(Client $client, string $start_date_time, string $end_date_time): ResponseInterface {
        $formatted_start_date_time = (new DateTime($start_date_time))->format('Ymd\THis\Z');
        $formatted_end_date_time = (new DateTime($end_date_time))->format('Ymd\THis\Z');
    
        $requestBody = <<<XML
    <?xml version="1.0" encoding="utf-8"?>
    <C:calendar-query xmlns:C="urn:ietf:params:xml:ns:caldav" xmlns:D="DAV:">
    <D:prop>
        <D:getetag/>
        <C:calendar-data/>
    </D:prop>
    <C:filter>
        <C:comp-filter name="VCALENDAR">
            <C:comp-filter name="VEVENT">
                <C:time-range start="{$formatted_start_date_time}" end="{$formatted_end_date_time}"/>
            </C:comp-filter>
        </C:comp-filter>
    </C:filter>
    </C:calendar-query>
    XML;
    
        $response = $client->request('REPORT', '', [
            'headers' => [
                'Content-Type' => 'application/xml; charset=UTF-8',
                'Depth' => '1',
            ],
            'body' => $requestBody,
        ]);
    
        // Get response body content first
        $responseBody = $response->getBody()->getContents();
        
        // Log debugging info
        log_message('info', "Formatted start date-time: $formatted_start_date_time");
        log_message('info', "Formatted end date-time: $formatted_end_date_time");
        log_message('info', "Sending CalDAV REPORT request with body: $requestBody");
        log_message('info', "Full CalDAV Response: " . $responseBody);
    
        // Create a new response with the same body content
        return $response->withBody(\GuzzleHttp\Psr7\Utils::streamFor($responseBody));
    }

    /**
     * Clean up old CalDAV unavailabilities
     *
     * @param int $provider_id
     * @param string $before_date
     */
    private function cleanup_old_caldav_unavailabilities(int $provider_id, string $before_date): void
    {
        $this->db
            ->where('id_users_provider', $provider_id)
            ->where('is_unavailability', true)
            ->where('end_datetime <', $before_date)
            ->like('notes', 'CalDAV Event:', 'after')
            ->delete('appointments');
    }
}
