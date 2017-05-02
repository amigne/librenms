<?php
/**
 * Unifi.php
 *
 * Ubiquiti Unifi
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package    LibreNMS
 * @link       http://librenms.org
 * @copyright  2017 Tony Murray
 * @author     Tony Murray <murraytony@gmail.com>
 */

namespace LibreNMS\OS;

use LibreNMS\Device\WirelessSensor;
use LibreNMS\Interfaces\Discovery\Sensors\WirelessCcqDiscovery;
use LibreNMS\Interfaces\Discovery\Sensors\WirelessClientsDiscovery;
use LibreNMS\Interfaces\Discovery\Sensors\WirelessFrequencyDiscovery;
use LibreNMS\Interfaces\Discovery\Sensors\WirelessPowerDiscovery;
use LibreNMS\Interfaces\Discovery\Sensors\WirelessUtilizationDiscovery;
use LibreNMS\Interfaces\Polling\Sensors\WirelessCcqPolling;
use LibreNMS\Interfaces\Polling\Sensors\WirelessFrequencyPolling;
use LibreNMS\OS;

class Unifi extends OS implements
    WirelessClientsDiscovery,
    WirelessCcqDiscovery,
    WirelessCcqPolling,
    WirelessFrequencyDiscovery,
    WirelessFrequencyPolling,
    WirelessPowerDiscovery,
    WirelessUtilizationDiscovery
{
    private $ccqDivisor = 10;

    /**
     * Returns an array of LibreNMS\Device\Sensor objects
     *
     * @return array Sensors
     */
    public function discoverWirelessClients()
    {
        $client_oids = snmpwalk_cache_oid($this->getDevice(), 'unifiVapNumStations', array(), 'UBNT-UniFi-MIB');
        if (empty($client_oids)) {
            return array();
        }
        $vap_radios = $this->getCacheByIndex('unifiVapRadio', 'UBNT-UniFi-MIB');
        $ssids = $this->getCacheByIndex('unifiVapEssId', 'UBNT-UniFi-MIB');

        $radios = array();
        foreach ($client_oids as $index => $entry) {
            $radio_name = $vap_radios[$index];
            $radios[$radio_name]['oids'][] = '.1.3.6.1.4.1.41112.1.6.1.2.1.8.' . $index;
            if (isset($radios[$radio_name]['count'])) {
                $radios[$radio_name]['count'] += $entry['unifiVapNumStations'];
            } else {
                $radios[$radio_name]['count'] = $entry['unifiVapNumStations'];
            }
        }

        $sensors = array();

        // discover client counts by radio
        foreach ($radios as $name => $data) {
            $sensors[] = new WirelessSensor(
                'clients',
                $this->getDeviceId(),
                $data['oids'],
                'unifi',
                $name,
                strtoupper($name) . ' Radio Clients',
                $data['count'],
                1,
                1,
                'sum',
                null,
                40,
                null,
                30
            );
        }

        // discover client counts by SSID
        foreach ($client_oids as $index => $entry) {
            $sensors[] = new WirelessSensor(
                'clients',
                $this->getDeviceId(),
                '.1.3.6.1.4.1.41112.1.6.1.2.1.8.' . $index,
                'unifi',
                $index,
                'SSID: ' . $ssids[$index],
                $entry['unifiVapNumStations']
            );
        }

        return $sensors;
    }

    /**
     * Returns an array of LibreNMS\Device\Sensor objects that have been discovered
     *
     * @return array
     */
    public function discoverWirelessCcq()
    {
        $ccq_oids = snmpwalk_cache_oid($this->getDevice(), 'unifiVapCcq', array(), 'UBNT-UniFi-MIB');
        if (empty($ccq_oids)) {
            return array();
        }
        $ssids = $this->getCacheByIndex('unifiVapEssId', 'UBNT-UniFi-MIB');

        $sensors = array();
        foreach ($ccq_oids as $index => $entry) {
            if ($ssids[$index]) { // don't discover ssids with empty names
                $sensors[] = new WirelessSensor(
                    'ccq',
                    $this->getDeviceId(),
                    '.1.3.6.1.4.1.41112.1.6.1.2.1.3.' . $index,
                    'unifi',
                    $index,
                    'SSID: ' . $ssids[$index],
                    min($entry['unifiVapCcq'] / $this->ccqDivisor, 100),
                    1,
                    $this->ccqDivisor
                );
            }
        }
        return $sensors;
    }

    /**
     * Poll wireless client connection quality
     * The returned array should be sensor_id => value pairs
     *
     * @param array $sensors Array of sensors needed to be polled
     * @return array of polled data
     */
    public function pollWirelessCcq(array $sensors)
    {
        if (empty($sensors)) {
            return array();
        }

        $ccq_oids = snmpwalk_cache_oid($this->getDevice(), 'unifiVapCcq', array(), 'UBNT-UniFi-MIB');

        $data = array();
        foreach ($sensors as $sensor) {
            $index = $sensor['sensor_index'];
            $data[$sensor['sensor_id']] = min($ccq_oids[$index]['unifiVapCcq'] / $this->ccqDivisor, 100);
        }

        return $data;
    }

    /**
     * Discover wireless frequency.  This is in MHz. Type is frequency.
     * Returns an array of LibreNMS\Device\Sensor objects that have been discovered
     *
     * @return array Sensors
     */
    public function discoverWirelessFrequency()
    {
        $data = snmpwalk_cache_oid($this->getDevice(), 'unifiVapChannel', array(), 'UBNT-UniFi-MIB');
        $vap_radios = $this->getCacheByIndex('unifiVapRadio', 'UBNT-UniFi-MIB');

        $sensors = array();
        foreach ($data as $index => $entry) {
            $radio = $vap_radios[$index];
            if (isset($sensors[$radio])) {
                continue;
            }
            $sensors[$radio] = new WirelessSensor(
                'frequency',
                $this->getDeviceId(),
                '.1.3.6.1.4.1.41112.1.6.1.2.1.4.' . $index,
                'unifi',
                $radio,
                strtoupper($radio) . ' Radio Frequency',
                WirelessSensor::channelToFrequency($entry['unifiVapChannel'])
            );
        }

        return $sensors;
    }

    /**
     * Poll wireless frequency as MHz
     * The returned array should be sensor_id => value pairs
     *
     * @param array $sensors Array of sensors needed to be polled
     * @return array of polled data
     */
    public function pollWirelessFrequency(array $sensors)
    {
        return $this->pollWirelessChannelAsFrequency($sensors);
    }

    /**
     * Discover wireless tx or rx power. This is in dBm. Type is power.
     * Returns an array of LibreNMS\Device\Sensor objects that have been discovered
     *
     * @return array
     */
    public function discoverWirelessPower()
    {
        $power_oids = snmpwalk_cache_oid($this->getDevice(), 'unifiVapTxPower', array(), 'UBNT-UniFi-MIB');
        if (empty($power_oids)) {
            return array();
        }
        $vap_radios = $this->getCacheByIndex('unifiVapRadio', 'UBNT-UniFi-MIB');

        // pick one oid for each radio, hopefully ssids don't change... not sure why this is supplied by vap
        $sensors = array();
        foreach ($power_oids as $index => $entry) {
            $radio_name = $vap_radios[$index];
            if (!isset($sensors[$radio_name])) {
                $sensors[$radio_name] = new WirelessSensor(
                    'power',
                    $this->getDeviceId(),
                    '.1.3.6.1.4.1.41112.1.6.1.2.1.21.' . $index,
                    'unifi-tx',
                    $radio_name,
                    'Tx Power: ' . strtoupper($radio_name) . ' Radio',
                    $entry['unifiVapTxPower']
                );
            }
        }

        return $sensors;
    }

    /**
     * Discover wireless utilization.  This is in %. Type is utilization.
     * Returns an array of LibreNMS\Device\Sensor objects that have been discovered
     *
     * @return array Sensors
     */
    public function discoverWirelessUtilization()
    {
        $util_oids = snmpwalk_cache_oid($this->getDevice(), 'unifiRadioCuTotal', array(), 'UBNT-UniFi-MIB');
        if (empty($util_oids)) {
            return array();
        }
        $util_oids = snmpwalk_cache_oid($this->getDevice(), 'unifiRadioCuSelfRx', $util_oids, 'UBNT-UniFi-MIB');
        $util_oids = snmpwalk_cache_oid($this->getDevice(), 'unifiRadioCuSelfTx', $util_oids, 'UBNT-UniFi-MIB');
        $radio_names = $this->getCacheByIndex('unifiRadioRadio', 'UBNT-UniFi-MIB');

        $sensors = array();
        foreach ($radio_names as $index => $name) {
            $radio_name = strtoupper($name) . ' Radio';
            $sensors[] = new WirelessSensor(
                'utilization',
                $this->getDeviceId(),
                '.1.3.6.1.4.1.41112.1.6.1.1.1.6.' . $index,
                'unifi-total',
                $index,
                'Total Util: ' . $radio_name,
                $util_oids[$index]['unifiRadioCuTotal']
            );
            $sensors[] = new WirelessSensor(
                'utilization',
                $this->getDeviceId(),
                '.1.3.6.1.4.1.41112.1.6.1.1.1.7.' . $index,
                'unifi-rx',
                $index,
                'Self Rx Util: ' . $radio_name,
                $util_oids[$index]['unifiRadioCuSelfRx']
            );
            $sensors[] = new WirelessSensor(
                'utilization',
                $this->getDeviceId(),
                '.1.3.6.1.4.1.41112.1.6.1.1.1.8.' . $index,
                'unifi-tx',
                $index,
                'Self Tx Util: ' . $radio_name,
                $util_oids[$index]['unifiRadioCuSelfTx']
            );
        }

        return $sensors;
    }
}
