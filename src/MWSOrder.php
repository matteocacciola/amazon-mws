<?php

namespace MCS;

use \InvalidArgumentException;
use \ReflectionClass;

class MWSOrder
{
    const STATUS_PARTIALLY_SHIPPED = 'PartiallyShipped';
    const STATUS_SHIPPED = 'Shipped';
    const STATUS_UNSHIPPED = 'Unshipped';
    const STATUS_PENDING_AVAILABILITY = 'PendingAvailability';
    const STATUS_PENDING = 'Pending';
    const STATUS_INVOICE_UNCONFIRMED = 'InvoiceUnconfirmed';
    const STATUS_CANCELED = 'Canceled';

    const ACK_STATUS_SUCCESS = 'Success';
    const ACK_STATUS_FAILURE = 'Failure';

    /**
     *
     * @param string $prefix
     *
     * @return array
     * @throws InvalidArgumentException
     */
    protected static function getConstants($prefix)
    {
        $oClass = new ReflectionClass(self::class);
        $const = $oClass->getConstants();

        $dump = [];
        foreach ($const as $key => $value) {
            if (substr($key, 0, strlen($prefix)) === $prefix) {
                $dump[] = $value; // $dump[$key] = $value;
            }
        }

        if (empty($dump)) {
            throw new InvalidArgumentException('Bad request: no constants found with prefix ' . $prefix, 400);
        } else {
            return $dump;
        }
    }

    /**
     *
     * @return array
     */
    public static function getStatuses()
    {
        return self::getConstants('STATUS');
    }

    /**
     *
     * @return array
     */
    public static function getAcknowledgementStatuses()
    {
        return self::getConstants('ACK');
    }
}
