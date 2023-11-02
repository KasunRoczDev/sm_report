<?php

namespace App\Utils;

use DateTime;

class DateFormatUtils
{
    public $date;

    const COMMON_DATE_FORMAT = 'm/d/Y h:i A';

    const MYSQL_DATE_FORMAT = 'Y-m-d H:i:s';

    public static function self($date)
    {
        $instance = new self();
        $instance->date = ! empty($date) ? $date : Carbon::now()->format('m/d/Y h:i A');

        return $instance;
    }

    /** Convert date to common date format
     * @return $this
     */
    public function convert_to_common_date_format()
    {
        $this->date = date(self::COMMON_DATE_FORMAT, strtotime($this->date));

        return $this;
    }

    /** ? this on convert any time format to this "m/d/Y h:i A" other wise get error when save to the database
     *
     * @dev Prabhath Wijewardhana
     *
     * @return $this
     */
    public function convert_to_mysql_date_format()
    {

        $formatted_date = DateTime::createFromFormat(self::COMMON_DATE_FORMAT, $this->date);
        //? check if date is valid or not
        if (! ($formatted_date && $formatted_date->format(self::COMMON_DATE_FORMAT) == $this->date)) {
            throw new Exception('Invalid date and time format');
        }

        // ! convert to mysql format
        $this->date = Carbon::createFromFormat(self::COMMON_DATE_FORMAT, $this->date)->format(self::MYSQL_DATE_FORMAT);

        return $this;
    }
}
