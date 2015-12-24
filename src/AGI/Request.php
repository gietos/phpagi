<?php

namespace gietos\AGI;

class Request
{
    /**
     * @var string The filename of your script
     */
    public $request;
    /**
     * @var array Script arguments
     */
    public $arguments = [];
    /**
     * @var string The originating channel (your phone)
     */
    public $channel;
    /**
     * @var string The language code (e.g. "en")
     */
    public $language;
    /**
     * @var string The originating channel type (e.g. "SIP" or "ZAP")
     */
    public $type;
    /**
     * @var string A unique ID for the call
     */
    public $uniqueId;
    /**
     * @var string The version of Asterisk (since Asterisk 1.6)
     */
    public $version;
    /**
     * @var string The caller ID number (or "unknown")
     */
    public $callerId;
    /**
     * @var string The caller ID name (or "unknown")
     */
    public $callerIdName;
    /**
     * @var string The presentation for the callerid in a ZAP channel
     */
    public $callingPres;
    /**
     * @var string The number which is defined in ANI2 see Asterisk Detailed Variable List (only for PRI Channels)
     */
    public $callingANI2;
    /**
     * @var string The type of number used in PRI Channels see Asterisk Detailed Variable List
     */
    public $callingTon;
    /**
     * @var string An optional 4 digit number (Transit Network Selector) used in PRI Channels see Asterisk Detailed
     * Variable List
     */
    public $callingTNS;
    /**
     * @var string The dialed number id (or "unknown")
     */
    public $dnid;
    /**
     * @var string Redirected Dial Number ID Service (or "unknown")
     */
    public $rdnis;
    /**
     * @var string Origin context in extensions.conf
     */
    public $context;
    /**
     * @var string The called number
     */
    public $extension;
    /**
     * @var string The priority that was executed as in the dial plan
     */
    public $priority;
    /**
     * @var string The flag value is 1.0 if started as an EAGI script, 0.0 otherwise
     */
    public $enhanced;
    /**
     * @var string Account code of the origin channel
     */
    public $accountCode;
    /**
     * @var string Thread ID of the AGI script (since Asterisk 1.6)
     */
    public $threadId;
}
