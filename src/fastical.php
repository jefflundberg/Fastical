<?php

/**
 * Fast ical parsing lib.
 * Parses vevents from ics file in given time range.
 *
 * @author msquare
 * @todo EXRULE
 * @todo Timezones
 */

namespace Fastical;
use Recurr;

class Fastical {

    var $timezone = null;

    var $constraint = null;

    var $transformer = null;

    var $ics_file = null;

    function __construct($ics_file) {
        if (! file_exists($ics_file))
            throw new Exception("File not found.");

        $this->ics_file = $ics_file;
    }

    function getEvents($start, $end) {
        if ($start > $end)
            throw new Exception("Start has to be before end.");

        $fd = @fopen($this->ics_file, 'r');
        if ($fd == null)
            throw new Exception("Unable to open ics file.");

        $this->timezone = new \DateTimeZone('Europe/Berlin');
        $this->transformer = new \Recurr\Transformer\ArrayTransformer();

        $events = array();
        while (($buffer = fgets($fd)) !== false) {
            if (rtrim($buffer) == 'BEGIN:VCALENDAR') {
                $events = $this->parseVCalendar($fd, $start, $end);
                break;
            }
        }

        if ($fd != null)
            fclose($fd);

        usort($events, function ($a, $b) {
            return $a['start'] > $b['start'];
        });

        return $events;
    }

    private function parseLine($line) {
        if (! strstr($line, ':'))
            return null;
        list($key, $value) = explode(':', $line);
        $params = array();
        if (strstr($key, ';')) {
            $tmp = explode(';', $key);
            $key = $tmp[0];
            for ($i = 1; $i < count($tmp); $i ++) {
                list($param_key, $param_value) = explode('=', $tmp[$i]);
                $params[$param_key] = $param_value;
            }
        }

        return array(
                'key' => $key,
                'value' => array(
                        'value' => $value,
                        'params' => $params
                )
        );
    }

    private function parseVEvent($fd, $start, $end) {
        $event = array();
        $line = '';
        while (($buffer = fgets($fd)) !== false) {
            if (rtrim($buffer) == 'BEGIN:VALARM')
                $this->parseVAlarm($fd, $start, $end);

            if (rtrim($buffer) == 'END:VEVENT')
                break;

            if (substr($buffer, 0, 1) == ' ')
                $line .= substr(rtrim($buffer), 1);
            else {
                if ($line != '') {
                    $line_data = $this->parseLine($line);
                    if ($line_data != null)
                        $event[$line_data['key']] = str_replace('\\\\,', ',', $line_data['value']);
                }
                $line = rtrim($buffer);
            }
        }

        return $this->validateVEvent($event, $start, $end);
    }

    private function validateVEvent($event, $start, $end) {
        $events = array();
        $override_events = array();

        $uid = null;
        if (isset($event['UID']))
            $uid = $event['UID']['value'];

        $dtstart = null;
        if (isset($event['DTSTART']))
            $dtstart = strtotime($event['DTSTART']['value']);

        $dtend = null;
        if (isset($event['DTEND']))
            $dtend = strtotime($event['DTEND']['value']);

        if (isset($event['DURATION']))
            $dtend = $dtstart + $this->decodeDuration($event['DURATION']['value']);

        $summary = null;
        if (isset($event['SUMMARY']))
            $summary = $event['SUMMARY']['value'];

        $description = null;
        if (isset($event['DESCRIPTION']))
            $description = $event['DESCRIPTION']['value'];

        $location = null;
        if (isset($event['LOCATION']))
            $location = $event['LOCATION']['value'];

        if (isset($event['RRULE'])) {
            $exdates = array();
            if (isset($event['EXDATE']))
                foreach (explode(',', $event['EXDATE']['value']) as $exdate)
                    $exdates[] = strtotime($exdate);

            if ($dtend == null)
                $rule = new \Recurr\Rule($event['RRULE']['value'], \DateTime::createFromFormat('U', $dtstart, $this->timezone), null, 'Europe/Berlin');
            else
                $rule = new \Recurr\Rule($event['RRULE']['value'], \DateTime::createFromFormat('U', $dtstart, $this->timezone), \DateTime::createFromFormat('U', $dtend, $this->timezone), 'Europe/Berlin');

            $this->constraint = new \Recurr\Transformer\Constraint\BetweenConstraint(\DateTime::createFromFormat('U', $start - ($dtend - $dtstart), $this->timezone), \DateTime::createFromFormat('U', $end + ($dtend - $dtstart), $this->timezone), true);

            foreach ($this->transformer->transform($rule, null, $this->constraint)->getValues() as $recurrance) {
                $dtstart = $recurrance->getStart()->getTimestamp();
                $dtend = $recurrance->getEnd()->getTimestamp();

                if (in_array($dtstart, $exdates))
                    continue;

                $events[] = array(
                        'start' => $dtstart,
                        'end' => $dtend,
                        'summary' => $summary,
                        'description' => $description,
                        'location' => $location,
                        'uid' => $uid
                );
            }
        } else {
            if (isset($event['RECURRENCE-ID'])) {
                $override_start = strtotime($event['RECURRENCE-ID']['value']);
                $override_end = $override_start + ($dtend - $dtstart);

                if ($override_start > $end && $dtstart > $end)
                    return array(
                            $events,
                            $override_events
                    );

                if ($override_end < $start && $dtend < $start)
                    return array(
                            $events,
                            $override_events
                    );

                $override_events[] = array(
                        'override_start' => $override_start,
                        'start' => $dtstart,
                        'end' => $dtend,
                        'summary' => $summary,
                        'description' => $description,
                        'location' => $location,
                        'uid' => $uid
                );
            } else {
                if ($dtstart > $end)
                    return array(
                            $events,
                            $override_events
                    );
                if ($dtend < $start)
                    return array(
                            $events,
                            $override_events
                    );

                $events[] = array(
                        'start' => $dtstart,
                        'end' => $dtend,
                        'summary' => $summary,
                        'description' => $description,
                        'location' => $location,
                        'uid' => $uid
                );
            }
        }

        return array(
                $events,
                $override_events
        );
    }

    private function parseVAlarm($fd, $start, $end) {
        while (($buffer = fgets($fd)) !== false)
            if (rtrim($buffer) == 'END:VALARM')
                return;
    }

    private function mergeEvents($events, $override_events, $start, $end) {
        foreach ($override_events as $override_event) {
            foreach ($events as $i => &$event) {
                if ($override_event['uid'] == $event['uid'] && $override_event['override_start'] == $event['start']) {
                    if ($override_event['start'] > $end || $override_event['end'] < $start)
                        unset($events[$i]);
                    else
                        $event = $override_event;
                }
            }
        }
        return $events;
    }

    private function parseVCalendar($fd, $start, $end) {
        $events = array();
        $override_events = array();
        while (($buffer = fgets($fd)) !== false) {
            switch (rtrim($buffer)) {
                case 'BEGIN:VEVENT':
                    list($new_events, $new_override_events) = $this->parseVEvent($fd, $start, $end);
                    foreach ($new_events as $event)
                        array_push($events, $event);
                    foreach ($new_override_events as $event)
                        array_push($override_events, $event);
                    break;

                case 'END:VCALENDAR':
                    return $this->mergeEvents($events, $override_events, $start, $end);
            }
        }
    }

    /**
     *
     * @todo Weeks, Days
     */
    private function decodeDuration($duration) {
        $d = 0;
        if (preg_match("/^PT(([0-9]+)H)?(([0-9]+)M)?(([0-9]+)S)?$/", $duration, $matches)) {
            if (isset($matches[2]))
                $d += $matches[2] * 60 * 60;
            if (isset($matches[4]))
                $d += $matches[4] * 60;
            if (isset($matches[6]))
                $d += $matches[6] * 60;
        }

        return $d;
    }
}

?>