<?php

namespace gietos\AGI;

class RequestParser
{
    private static $namesMap = [
        'uniqueid' => 'uniqueId',
        'callerid' => 'callerId',
        'calleridname' => 'callerIdName',
        'callingpres' => 'callingPres',
        'callingani2' => 'callingANI2',
        'callington' => 'callingTon',
        'callingtns' => 'callingTNS',
        'accountcode' => 'accountCode',
        'threadid' => 'threadId',
    ];

    /**
     * @param $rawRequest
     * @return Request
     * @throws RequestParseException
     */
    public static function createRequest($rawRequest)
    {
        $request = new Request();
        $lines = explode("\n", trim($rawRequest));
        foreach ($lines as $line) {
            if (preg_match('/agi_(?P<key>.+):(?P<value>.*)/', $line, $matches)) {
                if (array_key_exists($matches['key'], self::$namesMap)) {
                    $matches['key'] = self::$namesMap[$matches['key']];
                }
                if (preg_match('/arg_\d+/', $matches['key'])) {
                    $request->arguments[] = trim($matches['value']);
                    continue;
                }
                if (!property_exists($request, $matches['key'])) {
                    throw new RequestParseException('Unknown Request property: ' . $matches['key']);
                }
                $request->{$matches['key']} = trim($matches['value']);
            }
        }

        return $request;
    }
}
