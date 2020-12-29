<?php
/*
 * Copyright (c) 2019, The Jaeger Authors
 *
 * Licensed under the Apache License, Version 2.0 (the "License"); you may not use this file except
 * in compliance with the License. You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software distributed under the License
 * is distributed on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express
 * or implied. See the License for the specific language governing permissions and limitations under
 * the License.
 */

namespace Jaeger\Transport;

use Jaeger\Constants;
use Jaeger\Jaeger;
use Jaeger\JaegerThrift;
use Jaeger\Thrift\Batch;
use Jaeger\Thrift\Process;
use Jaeger\UdpClient;
use Thrift\Protocol\TCompactProtocol;
use Thrift\Transport\TMemoryBuffer;
use Jaeger\Thrift\Agent\AgentClient;
use Jaeger\Thrift\ClientStats;

class TransportUdp implements Transport
{
    /**
     * @var null|TMemoryBuffer
     */
    private $tran = null;

    /**
     * @var UdpClient|null
     */
    private $udpClient = null;

    /**
     * @var AgentClient|null
     */
    private $agentClient = null;

    /**
     * @var JaegerThrift|null
     */
    private $jaegerThrift = null;

    /**
     * @var Process|null
     */
    private $process = null;

    public static $hostPort = '';

    // sizeof(Span) * numSpans + processByteSize + emitBatchOverhead <= maxPacketSize
    public static $maxSpanBytes = 0;

    /**
     * @var Batch|null
     */
    public static $batch = null;

    public $agentServerHostPort = 'localhost:6831';

    /**
     * @var null|TCompactProtocol
     */
    public $protocol = null;

    public $procesSize = 0;

    public $bufferSize = 0;

    const MAC_UDP_MAX_SIZE = 9216;

    public function __construct($hostport = '', $maxPacketSize = '')
    {
        if ('' == $hostport) {
            $hostport = $this->agentServerHostPort;
        }
        self::$hostPort = $hostport;

        if (0 == $maxPacketSize) {
            $maxPacketSize = stristr(PHP_OS, 'DAR') ? self::MAC_UDP_MAX_SIZE : Constants\UDP_PACKET_MAX_LENGTH;
        }

        self::$maxSpanBytes = $maxPacketSize - Constants\EMIT_BATCH_OVER_HEAD;

        $this->tran = new TMemoryBuffer();

        $this->protocol = new TCompactProtocol($this->tran);

        $this->agentClient = new AgentClient($this->protocol, null);

        $this->udpClient = new UdpClient(self::$hostPort, $this->agentClient, $this->tran);

        $this->jaegerThrift = new JaegerThrift();
    }

    /**
     * 收集将要发送的追踪信息.
     *
     * @return bool
     */
    public function append(Jaeger $jaeger)
    {
        if (null == $this->process) {
            $this->buildAndCalcSizeOfProcessThrift($jaeger);
        }

        $thriftSpansBuffer = [];  // Uncommitted span used to temporarily store shards

        foreach ($jaeger->spans as $span) {
            $spanThrift = $this->jaegerThrift->buildSpanThrift($span);
            $spanSize = $this->getAndCalcSizeOfSerializedThrift($spanThrift);
            if ($spanSize > self::$maxSpanBytes) {
                //throw new \Exception("Span is too large");
                continue;
            }

            $thriftSpansBuffer[] = $spanThrift;
            $this->bufferSize += $spanSize;

            if ($this->bufferSize >= self::$maxSpanBytes) {
                self::$batch = new Batch([
                    'process' => $this->process,
                    'spans' => $thriftSpansBuffer,
                ]);
                $this->flush();
                $thriftSpansBuffer = [];  // Empty the temp buffer
            }
        }

        if (count($thriftSpansBuffer) > 0) {
            self::$batch = new Batch([
                'process' => $this->process,
                'spans' => $thriftSpansBuffer,
            ]);
            $this->flush();
        }

        $this->process = null;

        return true;
    }

    public function buildAndCalcSizeOfProcessThrift(Jaeger $jaeger)
    {
        $this->process = $this->jaegerThrift->buildProcessThrift($jaeger);
        $this->procesSize = $this->getAndCalcSizeOfSerializedThrift($this->process);
        $this->bufferSize += $this->procesSize;
    }

    /**
     * 计算序列化后的thrift字符长度.
     *
     * @param mixed $serializedThrift
     *
     * @return mixed
     */
    private function getAndCalcSizeOfSerializedThrift($thrift)
    {
        $thrift->write($this->protocol);
        $len = $this->tran->available();
        // 清空buf, 避免重复序列化
        $this->tran->read($len);

        return $len;
    }

    /**
     * @return int
     */
    public function flush()
    {
        if (self::$batch == null) {
            return 0;
        }

        $spanNum = count(self::$batch->spans);
        $this->udpClient->emitBatch(self::$batch);

        $this->resetBuffer();

        return $spanNum;
    }

    public function resetBuffer()
    {
        $this->bufferSize = $this->procesSize;
        self::$batch = null;
    }
}
