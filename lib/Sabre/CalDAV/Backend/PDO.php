<?php

namespace Sabre\CalDAV\Backend;

use
    Sabre\VObject,
    Sabre\CalDAV,
    Sabre\DAV,
    Sabre\DAV\Exception\Forbidden;

/**
 * PDO CalDAV backend
 *
 * This backend is used to store calendar-data in a PDO database, such as
 * sqlite or MySQL
 *
 * @copyright Copyright (C) 2007-2013 fruux GmbH (https://fruux.com/).
 * @author Evert Pot (http://evertpot.com/)
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class PDO extends AbstractBackend implements SyncSupport, SubscriptionSupport {

    /**
     * We need to specify a max date, because we need to stop *somewhere*
     *
     * On 32 bit system the maximum for a signed integer is 2147483647, so
     * MAX_DATE cannot be higher than date('Y-m-d', 2147483647) which results
     * in 2038-01-19 to avoid problems when the date is converted
     * to a unix timestamp.
     */
    const MAX_DATE = '2038-01-01';

    /**
     * pdo
     *
     * @var \PDO
     */
    protected $pdo;

    /**
     * The table name that will be used for calendars
     *
     * @var string
     */
    protected $calendarTableName;

    /**
     * The table name that will be used for calendar objects
     *
     * @var string
     */
    protected $calendarObjectTableName;

    /**
     * The table name that will be used for tracking changes in calendars.
     *
     * @var string
     */
    protected $calendarChangesTableName;

    /**
     * List of CalDAV properties, and how they map to database fieldnames
     * Add your own properties by simply adding on to this array.
     *
     * Note that only string-based properties are supported here.
     *
     * @var array
     */
    public $propertyMap = [
        '{DAV:}displayname'                          => 'displayname',
        '{urn:ietf:params:xml:ns:caldav}calendar-description' => 'description',
        '{urn:ietf:params:xml:ns:caldav}calendar-timezone'    => 'timezone',
        '{http://apple.com/ns/ical/}calendar-order'  => 'calendarorder',
        '{http://apple.com/ns/ical/}calendar-color'  => 'calendarcolor',
    ];

    /**
     * List of subscription properties, and how they map to database fieldnames.
     *
     * @var array
     */
    public $subscriptionPropertyMap = [
        '{DAV:}displayname'                                           => 'displayname',
        '{http://apple.com/ns/ical/}refreshrate'                      => 'refreshrate',
        '{http://apple.com/ns/ical/}calendar-order'                   => 'calendarorder',
        '{http://apple.com/ns/ical/}calendar-color'                   => 'calendarcolor',
        '{http://calendarserver.org/ns/}subscribed-strip-todos'       => 'striptodos',
        '{http://calendarserver.org/ns/}subscribed-strip-alarms'      => 'stripalarms',
        '{http://calendarserver.org/ns/}subscribed-strip-attachments' => 'stripattachments',
    ];

    /**
     * Creates the backend
     *
     * @param \PDO $pdo
     * @param string $calendarTableName
     * @param string $calendarObjectTableName
     */
    public function __construct(\PDO $pdo, $calendarTableName = 'calendars', $calendarObjectTableName = 'calendarobjects', $calendarChangesTableName = 'calendarchanges') {

        $this->pdo = $pdo;
        $this->calendarTableName = $calendarTableName;
        $this->calendarObjectTableName = $calendarObjectTableName;
        $this->calendarChangesTableName = $calendarChangesTableName;

    }

    /**
     * Returns a list of calendars for a principal.
     *
     * Every project is an array with the following keys:
     *  * id, a unique id that will be used by other functions to modify the
     *    calendar. This can be the same as the uri or a database key.
     *  * uri. This is just the 'base uri' or 'filename' of the calendar.
     *  * principaluri. The owner of the calendar. Almost always the same as
     *    principalUri passed to this method.
     *
     * Furthermore it can contain webdav properties in clark notation. A very
     * common one is '{DAV:}displayname'.
     *
     * Many clients also require:
     * {urn:ietf:params:xml:ns:caldav}supported-calendar-component-set
     * For this property, you can just return an instance of
     * Sabre\CalDAV\Property\SupportedCalendarComponentSet.
     *
     * @param string $principalUri
     * @return array
     */
    public function getCalendarsForUser($principalUri) {

        $fields = array_values($this->propertyMap);
        $fields[] = 'id';
        $fields[] = 'uri';
        $fields[] = 'synctoken';
        $fields[] = 'components';
        $fields[] = 'principaluri';
        $fields[] = 'transparent';

        // Making fields a comma-delimited list
        $fields = implode(', ', $fields);
        $stmt = $this->pdo->prepare("SELECT " . $fields . " FROM ".$this->calendarTableName." WHERE principaluri = ? ORDER BY calendarorder ASC");
        $stmt->execute([$principalUri]);

        $calendars = [];
        while($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {

            $components = [];
            if ($row['components']) {
                $components = explode(',',$row['components']);
            }

            $calendar = [
                'id' => $row['id'],
                'uri' => $row['uri'],
                'principaluri' => $row['principaluri'],
                '{' . CalDAV\Plugin::NS_CALENDARSERVER . '}getctag' => 'http://sabredav.org/ns/sync/' . ($row['synctoken']?$row['synctoken']:'0'),
                '{DAV:}sync-token' => $row['synctoken']?$row['synctoken']:'0',
                '{' . CalDAV\Plugin::NS_CALDAV . '}supported-calendar-component-set' => new CalDAV\Property\SupportedCalendarComponentSet($components),
                '{' . CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp' => new CalDAV\Property\ScheduleCalendarTransp($row['transparent']?'transparent':'opaque'),
            ];


            foreach($this->propertyMap as $xmlName=>$dbName) {
                $calendar[$xmlName] = $row[$dbName];
            }

            $calendars[] = $calendar;

        }

        return $calendars;

    }

    /**
     * Creates a new calendar for a principal.
     *
     * If the creation was a success, an id must be returned that can be used to reference
     * this calendar in other methods, such as updateCalendar
     *
     * @param string $principalUri
     * @param string $calendarUri
     * @param array $properties
     * @return string
     */
    public function createCalendar($principalUri, $calendarUri, array $properties) {

        $fieldNames = [
            'principaluri',
            'uri',
            'synctoken',
            'transparent',
        ];
        $values = [
            ':principaluri' => $principalUri,
            ':uri'          => $calendarUri,
            ':synctoken'    => 1,
            ':transparent'  => 0,
        ];

        // Default value
        $sccs = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';
        $fieldNames[] = 'components';
        if (!isset($properties[$sccs])) {
            $values[':components'] = 'VEVENT,VTODO';
        } else {
            if (!($properties[$sccs] instanceof CalDAV\Property\SupportedCalendarComponentSet)) {
                throw new DAV\Exception('The ' . $sccs . ' property must be of type: \Sabre\CalDAV\Property\SupportedCalendarComponentSet');
            }
            $values[':components'] = implode(',',$properties[$sccs]->getValue());
        }
        $transp = '{' . CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp';
        if (isset($properties[$transp])) {
            $values[':transparent'] = $properties[$transp]->getValue()==='transparent';
        }

        foreach($this->propertyMap as $xmlName=>$dbName) {
            if (isset($properties[$xmlName])) {

                $values[':' . $dbName] = $properties[$xmlName];
                $fieldNames[] = $dbName;
            }
        }

        $stmt = $this->pdo->prepare("INSERT INTO ".$this->calendarTableName." (".implode(', ', $fieldNames).") VALUES (".implode(', ',array_keys($values)).")");
        $stmt->execute($values);

        return $this->pdo->lastInsertId();

    }

    /**
     * Updates properties for a calendar.
     *
     * The mutations array uses the propertyName in clark-notation as key,
     * and the array value for the property value. In the case a property
     * should be deleted, the property value will be null.
     *
     * This method must be atomic. If one property cannot be changed, the
     * entire operation must fail.
     *
     * If the operation was successful, true can be returned.
     * If the operation failed, false can be returned.
     *
     * Deletion of a non-existent property is always successful.
     *
     * Lastly, it is optional to return detailed information about any
     * failures. In this case an array should be returned with the following
     * structure:
     *
     * [
     *   403 => [
     *      '{DAV:}displayname' => null,
     *   ],
     *   424 => [
     *      '{DAV:}owner' => null,
     *   ]
     * ]
     *
     * In this example it was forbidden to update {DAV:}displayname.
     * (403 Forbidden), which in turn also caused {DAV:}owner to fail
     * (424 Failed Dependency) because the request needs to be atomic.
     *
     * @param string $calendarId
     * @param array $mutations
     * @return bool|array
     */
    public function updateCalendar($calendarId, array $mutations) {

        $newValues = [];
        $result = [
            200 => [], // Ok
            403 => [], // Forbidden
            424 => [], // Failed Dependency
        ];

        $hasError = false;

        foreach($mutations as $propertyName=>$propertyValue) {

            switch($propertyName) {
                case '{' . CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp' :
                    $fieldName = 'transparent';
                    $newValues[$fieldName] = $propertyValue->getValue()==='transparent';
                    break;
                default :
                    // Checking the property map
                    if (!isset($this->propertyMap[$propertyName])) {
                        // We don't know about this property.
                        $hasError = true;
                        $result[403][$propertyName] = null;
                        unset($mutations[$propertyName]);
                        continue;
                    }

                    $fieldName = $this->propertyMap[$propertyName];
                    $newValues[$fieldName] = $propertyValue;
            }

        }

        // If there were any errors we need to fail the request
        if ($hasError) {
            // Properties has the remaining properties
            foreach($mutations as $propertyName=>$propertyValue) {
                $result[424][$propertyName] = null;
            }

            // Removing unused statuscodes for cleanliness
            foreach($result as $status=>$properties) {
                if (is_array($properties) && count($properties)===0) unset($result[$status]);
            }

            return $result;

        }

        // Success

        // Now we're generating the sql query.
        $valuesSql = [];
        foreach($newValues as $fieldName=>$value) {
            $valuesSql[] = $fieldName . ' = ?';
        }

        $stmt = $this->pdo->prepare("UPDATE " . $this->calendarTableName . " SET " . implode(', ',$valuesSql) . " WHERE id = ?");
        $newValues['id'] = $calendarId;
        $stmt->execute(array_values($newValues));

        $this->addChange($calendarId, "", 2);

        return true;

    }

    /**
     * Delete a calendar and all it's objects
     *
     * @param string $calendarId
     * @return void
     */
    public function deleteCalendar($calendarId) {

        $stmt = $this->pdo->prepare('DELETE FROM '.$this->calendarObjectTableName.' WHERE calendarid = ?');
        $stmt->execute([$calendarId]);

        $stmt = $this->pdo->prepare('DELETE FROM '.$this->calendarTableName.' WHERE id = ?');
        $stmt->execute([$calendarId]);

        $stmt = $this->pdo->prepare('DELETE FROM '.$this->calendarChangesTableName.' WHERE id = ?');
        $stmt->execute([$calendarId]);

    }

    /**
     * Returns all calendar objects within a calendar.
     *
     * Every item contains an array with the following keys:
     *   * id - unique identifier which will be used for subsequent updates
     *   * calendardata - The iCalendar-compatible calendar data
     *   * uri - a unique key which will be used to construct the uri. This can
     *     be any arbitrary string, but making sure it ends with '.ics' is a
     *     good idea. This is only the basename, or filename, not the full
     *     path.
     *   * lastmodified - a timestamp of the last modification time
     *   * etag - An arbitrary string, surrounded by double-quotes. (e.g.:
     *   '  "abcdef"')
     *   * calendarid - The calendarid as it was passed to this function.
     *   * size - The size of the calendar objects, in bytes.
     *
     * Note that the etag is optional, but it's highly encouraged to return for
     * speed reasons.
     *
     * The calendardata is also optional. If it's not returned
     * 'getCalendarObject' will be called later, which *is* expected to return
     * calendardata.
     *
     * If neither etag or size are specified, the calendardata will be
     * used/fetched to determine these numbers. If both are specified the
     * amount of times this is needed is reduced by a great degree.
     *
     * @param string $calendarId
     * @return array
     */
    public function getCalendarObjects($calendarId) {

        $stmt = $this->pdo->prepare('SELECT id, uri, lastmodified, etag, calendarid, size FROM '.$this->calendarObjectTableName.' WHERE calendarid = ?');
        $stmt->execute([$calendarId]);

        $result = [];
        foreach($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result[] = [
                'id'           => $row['id'],
                'uri'          => $row['uri'],
                'lastmodified' => $row['lastmodified'],
                'etag'         => '"' . $row['etag'] . '"',
                'calendarid'   => $row['calendarid'],
                'size'         => (int)$row['size'],
            ];
        }

        return $result;

    }

    /**
     * Returns information from a single calendar object, based on it's object
     * uri.
     *
     * The object uri is only the basename, or filename and not a full path.
     *
     * The returned array must have the same keys as getCalendarObjects. The
     * 'calendardata' object is required here though, while it's not required
     * for getCalendarObjects.
     *
     * @param string $calendarId
     * @param string $objectUri
     * @return array
     */
    public function getCalendarObject($calendarId,$objectUri) {

        $stmt = $this->pdo->prepare('SELECT id, uri, lastmodified, etag, calendarid, size, calendardata FROM '.$this->calendarObjectTableName.' WHERE calendarid = ? AND uri = ?');
        $stmt->execute([$calendarId, $objectUri]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if(!$row) return null;

        return [
            'id'           => $row['id'],
            'uri'          => $row['uri'],
            'lastmodified' => $row['lastmodified'],
            'etag'         => '"' . $row['etag'] . '"',
            'calendarid'   => $row['calendarid'],
            'size'         => (int)$row['size'],
            'calendardata' => $row['calendardata'],
         ];

    }

    /**
     * Returns a list of calendar objects.
     *
     * This method should work identical to getCalendarObject, but instead
     * return all the calendar objects in the list as an array.
     *
     * If the backend supports this, it may allow for some speed-ups.
     *
     * @param mixed $calendarId
     * @param array $uris
     * @return array
     */
    public function getMultipleCalendarObjects($calendarId, array $uris) {

        $query = 'SELECT id, uri, lastmodified, etag, calendarid, size, calendardata FROM '.$this->calendarObjectTableName.' WHERE calendarid = ? AND uri IN (';
        // Inserting a whole bunch of question marks
        $query.=implode(',', array_fill(0, count($uris), '?'));
        $query.=')';

        $stmt = $this->pdo->prepare($query);
        $stmt->execute(array_merge([$calendarId], $uris));

        $result = [];
        while($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {

            $result[] = [
                'id'           => $row['id'],
                'uri'          => $row['uri'],
                'lastmodified' => $row['lastmodified'],
                'etag'         => '"' . $row['etag'] . '"',
                'calendarid'   => $row['calendarid'],
                'size'         => (int)$row['size'],
                'calendardata' => $row['calendardata'],
            ];

        }
        return $result;

    }


    /**
     * Creates a new calendar object.
     *
     * The object uri is only the basename, or filename and not a full path.
     *
     * It is possible return an etag from this function, which will be used in
     * the response to this PUT request. Note that the ETag must be surrounded
     * by double-quotes.
     *
     * However, you should only really return this ETag if you don't mangle the
     * calendar-data. If the result of a subsequent GET to this object is not
     * the exact same as this request body, you should omit the ETag.
     *
     * @param mixed $calendarId
     * @param string $objectUri
     * @param string $calendarData
     * @return string|null
     */
    public function createCalendarObject($calendarId,$objectUri,$calendarData) {

        $extraData = $this->getDenormalizedData($calendarData);

        $stmt = $this->pdo->prepare('INSERT INTO '.$this->calendarObjectTableName.' (calendarid, uri, calendardata, lastmodified, etag, size, componenttype, firstoccurence, lastoccurence) VALUES (?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $calendarId,
            $objectUri,
            $calendarData,
            time(),
            $extraData['etag'],
            $extraData['size'],
            $extraData['componentType'],
            $extraData['firstOccurence'],
            $extraData['lastOccurence'],
        ]);
        $this->addChange($calendarId, $objectUri, 1);

        return '"' . $extraData['etag'] . '"';

    }

    /**
     * Updates an existing calendarobject, based on it's uri.
     *
     * The object uri is only the basename, or filename and not a full path.
     *
     * It is possible return an etag from this function, which will be used in
     * the response to this PUT request. Note that the ETag must be surrounded
     * by double-quotes.
     *
     * However, you should only really return this ETag if you don't mangle the
     * calendar-data. If the result of a subsequent GET to this object is not
     * the exact same as this request body, you should omit the ETag.
     *
     * @param mixed $calendarId
     * @param string $objectUri
     * @param string $calendarData
     * @return string|null
     */
    public function updateCalendarObject($calendarId,$objectUri,$calendarData) {

        $extraData = $this->getDenormalizedData($calendarData);

        $stmt = $this->pdo->prepare('UPDATE '.$this->calendarObjectTableName.' SET calendardata = ?, lastmodified = ?, etag = ?, size = ?, componenttype = ?, firstoccurence = ?, lastoccurence = ? WHERE calendarid = ? AND uri = ?');
        $stmt->execute([$calendarData, time(), $extraData['etag'], $extraData['size'], $extraData['componentType'], $extraData['firstOccurence'], $extraData['lastOccurence'], $calendarId, $objectUri]);

        $this->addChange($calendarId, $objectUri, 2);

        return '"' . $extraData['etag'] . '"';

    }

    /**
     * Parses some information from calendar objects, used for optimized
     * calendar-queries.
     *
     * Returns an array with the following keys:
     *   * etag
     *   * size
     *   * componentType
     *   * firstOccurence
     *   * lastOccurence
     *
     * @param string $calendarData
     * @return array
     */
    protected function getDenormalizedData($calendarData) {

        $vObject = VObject\Reader::read($calendarData);
        $componentType = null;
        $component = null;
        $firstOccurence = null;
        $lastOccurence = null;
        foreach($vObject->getComponents() as $component) {
            if ($component->name!=='VTIMEZONE') {
                $componentType = $component->name;
                break;
            }
        }
        if (!$componentType) {
            throw new \Sabre\DAV\Exception\BadRequest('Calendar objects must have a VJOURNAL, VEVENT or VTODO component');
        }
        if ($componentType === 'VEVENT') {
            $firstOccurence = $component->DTSTART->getDateTime()->getTimeStamp();
            // Finding the last occurence is a bit harder
            if (!isset($component->RRULE)) {
                if (isset($component->DTEND)) {
                    $lastOccurence = $component->DTEND->getDateTime()->getTimeStamp();
                } elseif (isset($component->DURATION)) {
                    $endDate = clone $component->DTSTART->getDateTime();
                    $endDate->add(VObject\DateTimeParser::parse($component->DURATION->value));
                    $lastOccurence = $endDate->getTimeStamp();
                } elseif ($component->DTSTART->getDateType()===VObject\Property\DateTime::DATE) {
                    $endDate = clone $component->DTSTART->getDateTime();
                    $endDate->modify('+1 day');
                    $lastOccurence = $endDate->getTimeStamp();
                } else {
                    $lastOccurence = $firstOccurence;
                }
            } else {
                $it = new VObject\RecurrenceIterator($vObject, (string)$component->UID);
                $maxDate = new \DateTime(self::MAX_DATE);
                if ($it->isInfinite()) {
                    $lastOccurence = $maxDate->getTimeStamp();
                } else {
                    $end = $it->getDtEnd();
                    while($it->valid() && $end < $maxDate) {
                        $end = $it->getDtEnd();
                        $it->next();

                    }
                    $lastOccurence = $end->getTimeStamp();
                }

            }
        }

        return [
            'etag' => md5($calendarData),
            'size' => strlen($calendarData),
            'componentType' => $componentType,
            'firstOccurence' => $firstOccurence,
            'lastOccurence'  => $lastOccurence,
        ];

    }

    /**
     * Deletes an existing calendar object.
     *
     * The object uri is only the basename, or filename and not a full path.
     *
     * @param string $calendarId
     * @param string $objectUri
     * @return void
     */
    public function deleteCalendarObject($calendarId,$objectUri) {

        $stmt = $this->pdo->prepare('DELETE FROM '.$this->calendarObjectTableName.' WHERE calendarid = ? AND uri = ?');
        $stmt->execute([$calendarId, $objectUri]);

        $this->addChange($calendarId, $objectUri, 3);

    }

    /**
     * Performs a calendar-query on the contents of this calendar.
     *
     * The calendar-query is defined in RFC4791 : CalDAV. Using the
     * calendar-query it is possible for a client to request a specific set of
     * object, based on contents of iCalendar properties, date-ranges and
     * iCalendar component types (VTODO, VEVENT).
     *
     * This method should just return a list of (relative) urls that match this
     * query.
     *
     * The list of filters are specified as an array. The exact array is
     * documented by \Sabre\CalDAV\CalendarQueryParser.
     *
     * Note that it is extremely likely that getCalendarObject for every path
     * returned from this method will be called almost immediately after. You
     * may want to anticipate this to speed up these requests.
     *
     * This method provides a default implementation, which parses *all* the
     * iCalendar objects in the specified calendar.
     *
     * This default may well be good enough for personal use, and calendars
     * that aren't very large. But if you anticipate high usage, big calendars
     * or high loads, you are strongly adviced to optimize certain paths.
     *
     * The best way to do so is override this method and to optimize
     * specifically for 'common filters'.
     *
     * Requests that are extremely common are:
     *   * requests for just VEVENTS
     *   * requests for just VTODO
     *   * requests with a time-range-filter on a VEVENT.
     *
     * ..and combinations of these requests. It may not be worth it to try to
     * handle every possible situation and just rely on the (relatively
     * easy to use) CalendarQueryValidator to handle the rest.
     *
     * Note that especially time-range-filters may be difficult to parse. A
     * time-range filter specified on a VEVENT must for instance also handle
     * recurrence rules correctly.
     * A good example of how to interprete all these filters can also simply
     * be found in \Sabre\CalDAV\CalendarQueryFilter. This class is as correct
     * as possible, so it gives you a good idea on what type of stuff you need
     * to think of.
     *
     * This specific implementation (for the PDO) backend optimizes filters on
     * specific components, and VEVENT time-ranges.
     *
     * @param string $calendarId
     * @param array $filters
     * @return array
     */
    public function calendarQuery($calendarId, array $filters) {

        $result = [];
        $validator = new \Sabre\CalDAV\CalendarQueryValidator();

        $componentType = null;
        $requirePostFilter = true;
        $timeRange = null;

        // if no filters were specified, we don't need to filter after a query
        if (!$filters['prop-filters'] && !$filters['comp-filters']) {
            $requirePostFilter = false;
        }

        // Figuring out if there's a component filter
        if (count($filters['comp-filters']) > 0 && !$filters['comp-filters'][0]['is-not-defined']) {
            $componentType = $filters['comp-filters'][0]['name'];

            // Checking if we need post-filters
            if (!$filters['prop-filters'] && !$filters['comp-filters'][0]['comp-filters'] && !$filters['comp-filters'][0]['time-range'] && !$filters['comp-filters'][0]['prop-filters']) {
                $requirePostFilter = false;
            }
            // There was a time-range filter
            if ($componentType == 'VEVENT' && isset($filters['comp-filters'][0]['time-range'])) {
                $timeRange = $filters['comp-filters'][0]['time-range'];

                // If start time OR the end time is not specified, we can do a
                // 100% accurate mysql query.
                if (!$filters['prop-filters'] && !$filters['comp-filters'][0]['comp-filters'] && !$filters['comp-filters'][0]['prop-filters'] && (!$timeRange['start'] || !$timeRange['end'])) {
                    $requirePostFilter = false;
                }
            }

        }

        if ($requirePostFilter) {
            $query = "SELECT uri, calendardata FROM ".$this->calendarObjectTableName." WHERE calendarid = :calendarid";
        } else {
            $query = "SELECT uri FROM ".$this->calendarObjectTableName." WHERE calendarid = :calendarid";
        }

        $values = [
            'calendarid' => $calendarId,
        ];

        if ($componentType) {
            $query.=" AND componenttype = :componenttype";
            $values['componenttype'] = $componentType;
        }

        if ($timeRange && $timeRange['start']) {
            $query.=" AND lastoccurence > :startdate";
            $values['startdate'] = $timeRange['start']->getTimeStamp();
        }
        if ($timeRange && $timeRange['end']) {
            $query.=" AND firstoccurence < :enddate";
            $values['enddate'] = $timeRange['end']->getTimeStamp();
        }

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($values);

        $result = [];
        while($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if ($requirePostFilter) {
                if (!$this->validateFilterForObject($row, $filters)) {
                    continue;
                }
            }
            $result[] = $row['uri'];

        }

        return $result;

    }

    /**
     * The getChanges method returns all the changes that have happened, since
     * the specified syncToken in the specified calendar.
     *
     * This function should return an array, such as the following:
     *
     * [
     *   'syncToken' => 'The current synctoken',
     *   'added'   => [
     *      'new.txt',
     *   ],
     *   'modified'   => [
     *      'modified.txt',
     *   ],
     *   'deleted' => [
     *      'foo.php.bak',
     *      'old.txt'
     *   ]
     * ];
     *
     * The returned syncToken property should reflect the *current* syncToken
     * of the calendar, as reported in the {DAV:}sync-token property This is
     * needed here too, to ensure the operation is atomic.
     *
     * If the $syncToken argument is specified as null, this is an initial
     * sync, and all members should be reported.
     *
     * The modified property is an array of nodenames that have changed since
     * the last token.
     *
     * The deleted property is an array with nodenames, that have been deleted
     * from collection.
     *
     * The $syncLevel argument is basically the 'depth' of the report. If it's
     * 1, you only have to report changes that happened only directly in
     * immediate descendants. If it's 2, it should also include changes from
     * the nodes below the child collections. (grandchildren)
     *
     * The $limit argument allows a client to specify how many results should
     * be returned at most. If the limit is not specified, it should be treated
     * as infinite.
     *
     * If the limit (infinite or not) is higher than you're willing to return,
     * you should throw a Sabre\DAV\Exception\TooMuchMatches() exception.
     *
     * If the syncToken is expired (due to data cleanup) or unknown, you must
     * return null.
     *
     * The limit is 'suggestive'. You are free to ignore it.
     *
     * @param string $calendarId
     * @param string $syncToken
     * @param int $syncLevel
     * @param int $limit
     * @return array
     */
    public function getChangesForCalendar($calendarId, $syncToken, $syncLevel, $limit = null) {

        // Current synctoken
        $stmt = $this->pdo->prepare('SELECT synctoken FROM calendars WHERE id = ?');
        $stmt->execute([ $calendarId ]);
        $currentToken = $stmt->fetchColumn(0);

        if (is_null($currentToken)) return null;

        $result = [
            'syncToken' => $currentToken,
            'added'     => [],
            'modified'  => [],
            'deleted'   => [],
        ];

        if ($syncToken) {

            $query = "SELECT uri, operation FROM " . $this->calendarChangesTableName . " WHERE synctoken >= ? AND synctoken < ? AND calendarid = ? ORDER BY synctoken";
            if ($limit>0) $query.= " LIMIT " . (int)$limit;

            // Fetching all changes
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([$syncToken, $currentToken, $calendarId]);

            $changes = [];

            // This loop ensures that any duplicates are overwritten, only the
            // last change on a node is relevant.
            while($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {

                $changes[$row['uri']] = $row['operation'];

            }

            foreach($changes as $uri => $operation) {

                switch($operation) {
                    case 1 :
                        $result['added'][] = $uri;
                        break;
                    case 2 :
                        $result['modified'][] = $uri;
                        break;
                    case 3 :
                        $result['deleted'][] = $uri;
                        break;
                }

            }
        } else {
            // No synctoken supplied, this is the initial sync.
            $query = "SELECT uri FROM calendarobjects WHERE calendarid = ?";
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([$calendarId]);

            $result['added'] = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }
        return $result;

    }

    /**
     * Adds a change record to the calendarchanges table.
     *
     * @param mixed $calendarId
     * @param string $objectUri
     * @param int $operation 1 = add, 2 = modify, 3 = delete.
     * @return void
     */
    protected function addChange($calendarId, $objectUri, $operation) {

        $stmt = $this->pdo->prepare('INSERT INTO ' . $this->calendarChangesTableName .' (uri, synctoken, calendarid, operation) SELECT ?, synctoken, ?, ? FROM calendars WHERE id = ?');
        $stmt->execute([
            $objectUri,
            $calendarId,
            $operation,
            $calendarId
        ]);
        $stmt = $this->pdo->prepare('UPDATE ' . $this->calendarTableName . ' SET synctoken = synctoken + 1 WHERE id = ?');
        $stmt->execute([
            $calendarId
        ]);

    }

    /**
     * Returns a list of subscriptions for a principal.
     *
     * Every subscription is an array with the following keys:
     *  * id, a unique id that will be used by other functions to modify the
     *    subscription. This can be the same as the uri or a database key.
     *  * uri. This is just the 'base uri' or 'filename' of the subscription.
     *  * principaluri. The owner of the subscription. Almost always the same as
     *    principalUri passed to this method.
     *  * source. Url to the actual feed
     *
     * Furthermore, all the subscription info must be returned too:
     *
     * 1. {DAV:}displayname
     * 2. {http://apple.com/ns/ical/}refreshrate
     * 3. {http://calendarserver.org/ns/}subscribed-strip-todos (omit if todos
     *    should not be stripped).
     * 4. {http://calendarserver.org/ns/}subscribed-strip-alarms (omit if alarms
     *    should not be stripped).
     * 5. {http://calendarserver.org/ns/}subscribed-strip-attachments (omit if
     *    attachments should not be stripped).
     * 7. {http://apple.com/ns/ical/}calendar-color
     * 8. {http://apple.com/ns/ical/}calendar-order
     *
     * @param string $principalUri
     * @return array
     */
    public function getSubscriptionsForUser($principalUri) {

        $fields = array_values($this->subscriptionPropertyMap);
        $fields[] = 'id';
        $fields[] = 'uri';
        $fields[] = 'source';
        $fields[] = 'principaluri';
        $fields[] = 'lastmodified';

        // Making fields a comma-delimited list
        $fields = implode(', ', $fields);
        $stmt = $this->pdo->prepare("SELECT " . $fields . " FROM calendarsubscriptions WHERE principaluri = ? ORDER BY calendarorder ASC");
        $stmt->execute([$principalUri]);

        $subscriptions = [];
        while($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {

            $subscription = [
                'id'           => $row['id'],
                'uri'          => $row['uri'],
                'principaluri' => $row['principaluri'],
                'source'       => $row['source'],
                'lastmodified' => $row['lastmodified'],
            ];

            foreach($this->subscriptionPropertyMap as $xmlName=>$dbName) {
                if (!is_null($row[$dbName])) {
                    $subscription[$xmlName] = $row[$dbName];
                }
            }

            $subscriptions[] = $subscription;

        }

        return $subscriptions;

    }

    /**
     * Creates a new subscription for a principal.
     *
     * If the creation was a success, an id must be returned that can be used to reference
     * this subscription in other methods, such as updateSubscription.
     *
     * @param string $principalUri
     * @param string $uri
     * @param array $properties
     * @return mixed
     */
    public function createSubscription($principalUri, $uri, array $properties) {

        $fieldNames = [
            'principaluri',
            'uri',
            'source',
            'lastmodified',
        ];

        if (!isset($properties['{http://calendarserver.org/ns/}source'])) {
            throw new Forbidden('The {http://calendarserver.org/ns/}source property is required when creating subscriptions');
        }

        $values = [
            ':principaluri' => $principalUri,
            ':uri'          => $uri,
            ':source'       => $properties['{http://calendarserver.org/ns/}source']->getHref(),
            ':lastmodified' => time(),
        ];

        foreach($this->subscriptionPropertyMap as $xmlName=>$dbName) {
            if (isset($properties[$xmlName])) {

                $values[':' . $dbName] = $properties[$xmlName];
                $fieldNames[] = $dbName;
            }
        }

        $stmt = $this->pdo->prepare("INSERT INTO calendarsubscriptions (".implode(', ', $fieldNames).") VALUES (".implode(', ',array_keys($values)).")");
        $stmt->execute($values);

        return $this->pdo->lastInsertId();

    }

    /**
     * Updates a subscription
     *
     * The mutations array uses the propertyName in clark-notation as key,
     * and the array value for the property value. In the case a property
     * should be deleted, the property value will be null.
     *
     * This method must be atomic. If one property cannot be changed, the
     * entire operation must fail.
     *
     * If the operation was successful, you can just return true.
     * If the operation failed, you may just return false.
     *
     * Deletion of a non-existent property is always successful.
     *
     * Lastly, it is optional to return detailed information about any
     * failures. In this case an array should be returned with the following
     * structure:
     *
     * array(
     *   403 => array(
     *      '{DAV:}displayname' => null,
     *   ),
     *   424 => array(
     *      '{DAV:}owner' => null,
     *   )
     * )
     *
     * In this example it was forbidden to update {DAV:}displayname.
     * (403 Forbidden), which in turn also caused {DAV:}owner to fail
     * (424 Failed Dependency) because the request needs to be atomic.
     *
     * @param mixed $subscriptionId
     * @param array $mutations
     * @return bool|array
     */
    public function updateSubscription($subscriptionId, array $mutations) {

        $newValues = [];
        $result = [
            200 => [], // Ok
            403 => [], // Forbidden
            424 => [], // Failed Dependency
        ];

        $hasError = false;

        foreach($mutations as $propertyName=>$propertyValue) {

            if ($propertyName === '{http://calendarserver.org/ns/}source') {
                $newValues['source'] = $propertyValue->getHref();
            } else {
                // Checking the property map
                if (!isset($this->subscriptionPropertyMap[$propertyName])) {
                    // We don't know about this property.
                    $hasError = true;
                    $result[403][$propertyName] = null;
                    unset($mutations[$propertyName]);
                    continue;
                }

                $fieldName = $this->subscriptionPropertyMap[$propertyName];
                $newValues[$fieldName] = $propertyValue;
            }

        }

        // If there were any errors we need to fail the request
        if ($hasError) {
            // Properties has the remaining properties
            foreach($mutations as $propertyName=>$propertyValue) {
                $result[424][$propertyName] = null;
            }

            // Removing unused statuscodes for cleanliness
            foreach($result as $status=>$properties) {
                if (is_array($properties) && count($properties)===0) unset($result[$status]);
            }

            return $result;

        }

        // Success

        // Now we're generating the sql query.
        $valuesSql = [];
        foreach($newValues as $fieldName=>$value) {
            $valuesSql[] = $fieldName . ' = ?';
        }

        $stmt = $this->pdo->prepare("UPDATE calendarsubscriptions SET " . implode(', ',$valuesSql) . ", lastmodified = ? WHERE id = ?");
        $newValues['lastmodified'] = time();
        $newValues['id'] = $subscriptionId;
        $stmt->execute(array_values($newValues));

        return true;

    }

    /**
     * Deletes a subscription
     *
     * @param mixed $subscriptionId
     * @return void
     */
    public function deleteSubscription($subscriptionId) {

        $stmt = $this->pdo->prepare('DELETE FROM calendarsubscriptions WHERE id = ?');
        $stmt->execute([$subscriptionId]);

    }

}
