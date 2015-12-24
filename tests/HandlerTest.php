<?php

use gietos\AGI;

class HandlerTest extends PHPUnit_Framework_TestCase
{
    public $request = <<<EOT
agi_request: AGITest.php
agi_channel: SIP/provider-0000000c
agi_language: ru
agi_type: SIP
agi_uniqueid: 1450911661.12
agi_version: 13.1.0~dfsg-1ubuntu2
agi_callerid: 79267629004
agi_calleridname: 79267629004
agi_callingpres: 0
agi_callingani2: 0
agi_callington: 0
agi_callingtns: 0
agi_dnid: 71234567890
agi_rdnis: unknown
agi_context: incoming
agi_extension: 9
agi_priority: 1
agi_enhanced: 0.0
agi_accountcode:
agi_threadid: 139668957538048
agi_arg_1: test


EOT;

    public function testInitialize()
    {
        $a = new \gietos\AGI\Handler();

        $this->assertInstanceOf('gietos\\AGI\\Handler', $a);
    }

    public function testParseRequest()
    {
        $in = fopen('php://temp', 'r+');
        fputs($in, $this->request);
        rewind($in);

        $a = new \gietos\AGI\Handler([
            'in' => $in,
        ]);
        $a->handleRequest();

        $this->assertAttributeInstanceOf('gietos\\AGI\\Request', 'request', $a);
        $this->assertAttributeEquals('AGITest.php', 'request', $a->request);
        $this->assertAttributeEquals('SIP/provider-0000000c', 'channel', $a->request);
        $this->assertAttributeEquals('ru', 'language', $a->request);
        $this->assertAttributeEquals('SIP', 'type', $a->request);
        $this->assertAttributeEquals('1450911661.12', 'uniqueId', $a->request);
        $this->assertAttributeEquals('13.1.0~dfsg-1ubuntu2', 'version', $a->request);
        $this->assertAttributeEquals('79267629004', 'callerId', $a->request);
        $this->assertAttributeEquals('79267629004', 'callerIdName', $a->request);
        $this->assertAttributeEquals('0', 'callingPres', $a->request);
        $this->assertAttributeEquals('0', 'callingANI2', $a->request);
        $this->assertAttributeEquals('0', 'callingTon', $a->request);
        $this->assertAttributeEquals('0', 'callingTNS', $a->request);
        $this->assertAttributeEquals('71234567890', 'dnid', $a->request);
        $this->assertAttributeEquals('unknown', 'rdnis', $a->request);
        $this->assertAttributeEquals('incoming', 'context', $a->request);
        $this->assertAttributeEquals('9', 'extension', $a->request);
        $this->assertAttributeEquals('1', 'priority', $a->request);
        $this->assertAttributeEquals('0.0', 'enhanced', $a->request);
        $this->assertAttributeEquals('', 'accountCode', $a->request);
        $this->assertAttributeEquals('139668957538048', 'threadId', $a->request);
        $this->assertAttributeEquals(['test'], 'arguments', $a->request);
    }
}
