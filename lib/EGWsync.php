<?php

/**
 * lib/EGWSYNC.php.
 *
 * This class makes use of the EGW class to add/remove switches and erls to an E911 gateway appliance.
 * 
 *
 * PHP version 5
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 3.0 of the License, or (at your option) any later version.
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category  default
 *
 * @author    Andrew Jones
 * @copyright 2016 @authors
 * @license   http://www.gnu.org/copyleft/lesser.html The GNU LESSER GENERAL PUBLIC LICENSE, Version 3.0
 */
namespace ohtarr;

class EGWsync
{
	public $NM_SWITCHES;		//array of switches from Network Management Platform
	public $NM_ELINS;			//array of elins from Network Management Platform
	public $E911_SWITCHES;		//array of switches from e911 appliance
	public $E911_ERLS;			//array of ERLs from e911 appliance
	public $SNOW_LOCS;			//array of locations from SNOW
	public $logmsg = "";

    public function __construct()
	{
		global $DB;
		$this->NM_SWITCHES = $this->Netman_get_switches();		//populate array of switches from Network Management Platform
		$this->E911_SWITCHES = $this->E911_get_switches();		//populate array of switches from E911 Appliance
		$this->E911_ERLS = $this->E911_get_erls();				//populate list of ERLs from E911 Appliance
		$this->SNOW_LOCS = $this->Snow_get_valid_locations();	//populate a list of locations from SNOW
		$this->NM_ELINS = $this->NM_get_elins();				//populate a list of elins from Network Management Platform

/*
		if (empty($this->NM_SWITCHES)		||
			empty($this->E911_SWITCHES)		||
			empty($this->E911_ERLS)			||
			empty($this->SNOW_LOCS)			||
			empty($this->NM_ELINS)
			)
		{
			$DB->log("EGWsync failed: 1 or more data sources are empty!");
			exit();
		}
/**/
	}

	public function __destruct()
	{
		global $DB;
		if ($this->logmsg){
			$DB->log($this->logmsg);		
		}
	}

	/*
	Hit a reporting API to log all automation tasks.
	$params format:
							[	"origin_hostname"	=>	"test",
								"processname"		=>	"tester",
								"category"			=>	"Network",
								"timesaved"			=>	"2",
								"datestarted"		=>	"2016-05-26 09:34:00.000",
								"datefinished"		=>	"2016-05-26 09:35:00.000",
								"success"			=>	"1",
					//			"target_hostname"	=>	"1",	//optional
					//			"triggeredby"		=>	test,	//optional
					//			"description"		=>	test,	//optional
					//			"target_ip"			=>	test,	//optional
					//			"notes"				=>	test,	//optional
					];
	/**/

	public function automation_report($params)
	{
		if(!$params){
			$params = [];
		}
		$baseparams = [	"origin_hostname"	=>	"netman",
						"processname"		=>	"E911_EGWSYNC",
						"category"			=>	"Network",
						"timesaved"			=>	"5",
						"datestarted"		=>	date('Y/m/d H:i:s'),
						"datefinished"		=>	date('Y/m/d H:i:s'),
						"success"			=>	"1",
						"target_hostname"	=>	"E911_EGW",											//optional
						"triggeredby"		=>	"netman",											//optional
						"description"		=>	"Netman E911_EGWSYNC function completed",			//optional
						"target_ip"			=>	"10.123.123.70",									//optional
						"notes"				=>	"A generic E911_EGW function as been completed",	//optional
		];
		$newparams = array_merge($baseparams, $params);
		$URI = API_REPORTING_URL;											//api to hit e911 raw DB
		$response = \Httpful\Request::post($URI)								//Build a GET request...
								->authenticateWith(LDAP_USER, LDAP_PASS)		//basic authentication
								->body($newparams)									//parameters to send in body
								->sendsType(\Httpful\Mime::FORM)				//we are sending basic forms
								->send()										//execute the request
								->body;											//only give us the body back
		return $response;
	}

	/*
    [WCDBCVAN] => Array
        (
            [zip] => V5C 0G5
            [u_street_2] => 
            [street] => 123 Fast Creek Drive
            [name] => XXXXXXXX
            [state] => BC
            [sys_id] => 11ccf5b16ffb020034cb07321c3ee4b1
            [country] => CA
            [city] => Burnaby
        )
	/**/
	public function Snow_get_valid_locations(){

		$SNOW = new \ohtarr\ServiceNowRestClient;		//new snow rest api instance

		$PARAMS = array(								//parameters needed for SNOW API call
							"u_active"                	=>	"true",
							"u_e911_validated"			=>	"true",
							"sysparm_fields"        	=>	"sys_id,name,street,u_street_2,city,state,zip,country",
		);

		$RESPONSE = $SNOW->SnowTableApiGet("cmn_location", $PARAMS);	//get all locations from snow api
		foreach($RESPONSE as $loc){										//loop through all locations returned from snow
			$snowlocs[$loc[name]] = $loc;								//build new array with sitecode as the key
		}
		ksort($snowlocs);												//sort by key
		return $snowlocs;												//return new array
	}

	/*
	returns array of active switches from Network Management Platform

    [wscganorswd01] => Array
        (
            [id] => 39716
            [name] => xxxxxxxxswd01
            [ip] => 10.5.5.1
            [snmploc] => Array
                (
                    [site] => xxxxxxxx
                    [erl] => xxxxxxxx
                    [desc] => xxxxxxxx
                )

        )
	/**/
	public function Netman_get_switches(){

		$SEARCH = array(    // Search for all cisco network devices
                "category"              =>	"Management",
                "type"                  =>	"Device_Network_Cisco",
                );

		$RESULTS = \Information::search($SEARCH);							// perform the search, returns object ids
	
		foreach($RESULTS as $OBJECTID){										//loop through switch ids returned
			$DEVICE = \Information::retrieve($OBJECTID);					//returns entire object $OBJECTID
			$reg = "/^\D{5}\S{3}.*(sw[acdpi]|SW[ACDPI])[0-9]+\S*$/";		//regex to match switches only
			//$reg = "/^\D{5}\S{3}.*(sw[acdpi]|SW[ACDPI])[0-9]*$/";			//regex to match switches only
			if (preg_match($reg,$DEVICE->data['name'], $hits)){				//if device name matches regex
				$NMSNMP = $DEVICE->get_snmp_location();						//retrieve the snmp-server location from switch object
				//build new array
				$switches[$DEVICE->data['name']][id]			= $OBJECTID;
				$switches[$DEVICE->data['name']][name]			= $DEVICE->data['name'];
				$switches[$DEVICE->data['name']][ip]			= $DEVICE->data['ip'];
				$switches[$DEVICE->data['name']][snmploc][site]	= $NMSNMP[site];
				$switches[$DEVICE->data['name']][snmploc][erl]	= $NMSNMP[erl];
				$switches[$DEVICE->data['name']][snmploc][desc]	= $NMSNMP[desc];
			}
		}
		ksort($switches);		//sort by key
		return $switches;		//return new array
	}

	/*
	returns array of switches from e911 appliance

    [wcdbcvanswp04] => Array
        (
            [id] => 4827
            [name] => wcdbcvanswp04
            [ip] => 10.172.224.144
            [erlid] => 741
        )
	/**/
	public function E911_get_switches(){
		$URI = BASEURL . "/api/911-get-switches.php";			//api to hit e911 raw DB
		//print $URI;
		$E911_switches = \Httpful\Request::get($URI)								//Build a GET request...
								->expectsJson()										//we expect JSON back from the api
								->parseWith("\\Metaclassing\\Utility::decodeJson")	//Parse and convert to an array with our own parser, rather than the default httpful parser
								->send()											//send the request.
								->body;											
		foreach($E911_switches as $key => $switch){						//loop through each switch returned
			//build new array
			$E911_switch_array[$switch[switch_description]][id]		= $switch[switch_id];
			$E911_switch_array[$switch[switch_description]][name]	= $switch[switch_description];
			$E911_switch_array[$switch[switch_description]][ip]		= $switch[switch_ip];
			$E911_switch_array[$switch[switch_description]][erlid]	= $switch[switch_default_erl_id];
		}
		ksort($E911_switch_array);				//sort by key
		return $E911_switch_array;				//return our new array
	}

	/*
	returns array of switches from e911 appliance

    [WCDBCVAN] => Array
        (
            [id] => 741
            [name] => xxxxxxxx
            [street] => FAST CREEK DRIVE
            [hno] => 123
            [prd] => 
            [rd] => FAST CREEK DRIVE
            [sts] => 
            [city] => BURNABY
            [state] => BC
            [zip] => V5C 0G5
            [country] => CAN
            [custname] => WCDBCVAN
            [loc] => 
            [elins] => 1231231234
        )
	/**/
	public function E911_get_erls(){
		$URI = BASEURL . "/api/911-get-locations.php";				//api to hit e911 raw database
		$E911_erls = \Httpful\Request::get($URI)									//Build a GET request...
								->expectsJson()										//we expect JSON back from the api
								->parseWith("\\Metaclassing\\Utility::decodeJson")	//Parse and convert to an array with our own parser, rather than the default httpful parser
								->send()											//send the request.
								->body;	

		$EGW = new \EmergencyGateway\EGW(	E911_ERL_SOAP_URL,					//setup our e911 api call
											E911_ERL_SOAP_WSDL,
											E911_SOAP_USER,
											E911_SOAP_PASS);

		foreach($E911_erls as $key => $erl){									//loop through erls from E911 appliance
			//build our new array
			if ($erl[erl_id]){
				$E911_erl_array[$erl[erl_id]][id] 		= $erl[location_id];
				$E911_erl_array[$erl[erl_id]][name] 	= $erl[erl_id];
				$E911_erl_array[$erl[erl_id]][street] 	= $erl[street_name];
				//Why hit EGW api when I have all the details from restful call above?
				//print "[" . $erl[erl_id] . "]\n";
				try{
					$RESULT = $EGW->getERL($erl[erl_id]);
				} catch (\Exception $e) {
					print "[$erl[erl_id]] failed with exception: {$e->getMessage()}, ";
				}
			} else {
				continue;
			}
			/**/
			$params = get_object_vars($RESULT[civicAddress]);
			$E911_erl_array[$erl[erl_id]][hno] 		= $params[HNO];
			$E911_erl_array[$erl[erl_id]][prd] 		= $params[PRD];
			$E911_erl_array[$erl[erl_id]][rd] 		= $params[RD];
			$E911_erl_array[$erl[erl_id]][sts]		= $params[STS];
			$E911_erl_array[$erl[erl_id]][city] 	= $params[A3];
			$E911_erl_array[$erl[erl_id]][state] 	= $params[A1];
			$E911_erl_array[$erl[erl_id]][zip] 		= $params[PC];
			$E911_erl_array[$erl[erl_id]][country] 	= $params[country];
			$E911_erl_array[$erl[erl_id]][custname]	= $params[NAM];
			$E911_erl_array[$erl[erl_id]][loc]		= $params[LOC];
			$E911_erl_array[$erl[erl_id]][elins]	= $RESULT[elins];
		}
		ksort($E911_erl_array);					//sort by key
		return $E911_erl_array;					//return our new array
	}

	/*
	returns array of elins from Network Management platform

    [991604] => Array
        (
            [id] => 991604
            [number] => 1231231234
            [parent] => 991504
            [name] => Available
        )

	/**/
	public function NM_get_elins(){
		$SEARCH = array(    // Search for all DIDs with paren 991504
                "category"              =>	"IPPlan",
                "type"                  =>	"DID",
				"parent"				=>	"991504",
                );

		// Do the actual search
		$RESULTS = \Information::search($SEARCH);		//returns object IDs of each object that matched our search
	
		foreach($RESULTS as $OBJECTID){											//loop through the DIDs
			$DEVICE = (array) \Information::retrieve($OBJECTID);				//retrieve the object from DB
			//build our new array
			$elins[$OBJECTID][id]				=	$DEVICE[data][id];
			$elins[$OBJECTID][number]			=	$DEVICE[data][number];
			$elins[$OBJECTID][parent]			=	$DEVICE[data][parent];
			$elins[$OBJECTID][name]				=	$DEVICE[data][name];
		}
		return($elins);				//return our new array
	}

	/*
	returns array of erl names that need to be added to the e911 appliance (active and validated in SNOW)
	/**/
	public function erls_to_add(){
		$erlstoadd = array_diff_key($this->SNOW_LOCS,$this->E911_ERLS);		//compare SNOW_LOCS to E911_ERLS, we are left with the differences
		$erlstoadd = array_keys($erlstoadd);
		if (!empty($erlstoadd)){
			return $erlstoadd;					//return an array of ERL NAMES
		}
	}

	/*
	returns array of erl names that need to be removed from the e911 appliance (inactive and unvalidated in SNOW)
	/**/
	public function erls_to_remove(){
		foreach($this->E911_ERLS as $erlname => $erl){											//go through all e911 erls
			$exploded = explode("_", $erlname);									//we break apart the erl using the "_" as a delimiter
			$defaulterls[]=$exploded[0];											//add only the sitecodes of all erls to new array $sites
		}
		$defaulterls = array_values(array_unique($defaulterls));
		//print "defaulterls: \n";
		//print_r($defaulterls);
		//print "SNOW LOCS KEYS: \n";
		//print_r(array_keys($this->SNOW_LOCS));
		$deldeferls = array_diff($defaulterls,array_keys($this->SNOW_LOCS));		//compare E911_ERLS to SNOW_LOCS, we are left with the differences
		//print "deldeferls: \n";
		//print_r($deldeferls);
		foreach($deldeferls as $deferl){
			$dellall[] = $deferl;
			foreach($this->E911_ERLS as $erlname=>$erl){
				if(substr($erlname, 0, 8) == $deferl){
					$dellall[] = $erlname;
				}
			}
		}
		//print "dellall= \n";
		//print_r($dellall);
		$dellall = array_values(array_unique($dellall));
		if(!empty($dellall)){
			return $dellall;				//return an array of ERL NAMES
		}
	}

	/*
	returns array of erl names that need to be modified (address information changed in SNOW)
	/**/
	public function erls_to_modify(){
		foreach($this->E911_ERLS as $erlname => $erl){				//loop through E911_ERLS
			if ($this->SNOW_LOCS[$erlname]){						//if the ERL exists as a SNOW_LOC
				unset($erlelin);
				foreach($this->NM_ELINS as $did => $elin){			//loop through NM_ELINS
					if($elin[name] == $erlname){					//find an elin that is already assigned to this site
						$erlelin = $elin[number];					//found a matching elin assigned to this location already
						break;										//no need to continue looping
					}
				}
				//if street, city, state, zip, country, street2, and elins don't match
				if (!empty(strcmp(strtoupper($this->SNOW_LOCS[$erlname][street]),		strtoupper($erl[hno] . " " . $erl[street])))		||
					!empty(strcmp(strtoupper($this->SNOW_LOCS[$erlname][city],			strtoupper($erl[city]))))							||
					!empty(strcmp(strtoupper($this->SNOW_LOCS[$erlname][state]),		strtoupper($erl[state])))							||
					!empty(strcmp(strtoupper($this->SNOW_LOCS[$erlname][zip]),			strtoupper($erl[zip])))								||
					!empty(strcmp(strtoupper($this->SNOW_LOCS[$erlname][country]),		strtoupper($erl[country])))							||					
					!empty(strcmp(strtoupper($this->SNOW_LOCS[$erlname][u_street_2]),	strtoupper($erl[loc])))								||
					!empty(strcmp(strtoupper($erlelin),									strtoupper($erl[elins])))){							

						print "****************************NO MATCH! ********************************\n";
						print "ERL: " . $erlname . "\n";
						print strtoupper($this->SNOW_LOCS[$erlname][street])	. "=" . strtoupper($erl[hno] . " " . $erl[street])	. "\n";
						print strtoupper($this->SNOW_LOCS[$erlname][city])		. "=" . strtoupper($erl[city])						. "\n";
						print strtoupper($this->SNOW_LOCS[$erlname][state])		. "=" . strtoupper($erl[state])						. "\n";
						print strtoupper($this->SNOW_LOCS[$erlname][zip])		. "=" . strtoupper($erl[zip])						. "\n";
						print strtoupper($this->SNOW_LOCS[$erlname][country])	. "=" . strtoupper($erl[country])					. "\n";
						print strtoupper($this->SNOW_LOCS[$erlname][u_street_2]). "=" . strtoupper($erl[loc])						. "\n";
						print strtoupper($erlelin)								. "=" . strtoupper($erl[elins])						. "\n";
						print "****************************NO MATCH! ********************************\n";
/**/
						$modify_erls[] = $erlname;			//add the erl to our list to modify
				}
			}
		}
		if (!empty($modify_erls)){
			return $modify_erls;				//return our list to modify
		}
	}

	/*
	returns array of switch names that need to be added to e911 appliance (active in Network Management Platform)
	/**/
	public function switches_to_add(){
		if($this->E911_SWITCHES){		//if there are any E911 switches
			$switchdiff = array_keys(array_diff_key($this->NM_SWITCHES,$this->E911_SWITCHES));	//compare NM SWITCHES to E911 SWITCHES and create an array of switch names
		} else {							//if there are no switches in E911 appliance
			$switchdiff = array_keys($this->NM_SWITCHES);		//add ALL switches in Network Management Platform to array
		}
		
		//print_r($switchdiff);
		foreach($switchdiff as $switchname){		//loop through switches
			//print "SWITCH : " . $switchname . "\n";
			if($this->NM_SWITCHES[$switchname][snmploc][erl]){		//if the switch has a configure ERL
				//print "ERL " . $this->NM_SWITCHES[$switchname][snmploc][erl] . " EXISTS ON SWITCH SNMP LOC CONFIG \n";
				if ($this->NM_SWITCHES[$switchname][snmploc][erl] == $this->E911_ERLS[$this->NM_SWITCHES[$switchname][snmploc][erl]][name]){		//If the configure switch ERL matches an existing E911 ERL
					//print "SWITCH SNMP LOC ERL MATCHES an E911 ERL \n";
					$switchestoadd[] = $switchname;		//Add switch to our final new array
				}
			}
		}
		if (!empty($switchestoadd)){
			return $switchestoadd;				//return new array
		}
	}

	/*
	returns array of switch names that need to be modified in the e911 appliance (ip or erl change)
	/**/
	public function switches_to_modify(){
		foreach($this->E911_SWITCHES as $switchname => $switch){		//loop through all E911 Switches
			unset($switcherlname);
			foreach($this->E911_ERLS as $erlname => $erl){			//loop through each E911 ERL
				if($switch[erlid] == $erl[id]){					//find an erlname that matches assigned erl id
					$switcherlname = $erl[name];				//save the erl name in variable for later use
					break;										//no need to loop any further
				}
			}
			if ($this->NM_SWITCHES[$switchname]){		//If the switch exists in NM
				//if the IP and ERL name in E911 switch no longer match NM IP and ERL name
				if (!empty(strcmp(strtoupper($this->NM_SWITCHES[$switchname][ip]),				strtoupper($switch[ip])))		||
					!empty(strcmp(strtoupper($this->NM_SWITCHES[$switchname][snmploc][erl]),	strtoupper($switcherlname)))){							
						print "****************************NO MATCH! ********************************\n";
						print "SWITCH: " . $switchname . "\n";
						print strtoupper($this->NM_SWITCHES[$switchname][ip])				. "=" . strtoupper($switch[ip])		. "\n";
						print strtoupper($this->NM_SWITCHES[$switchname][snmploc][erl])		. "=" . strtoupper($switcherlname)	. "\n";
						print "****************************NO MATCH! ********************************\n";
						$modify_switches[] = $switchname;		//add switch to new array
				}
			}
		}
		if (!empty($modify_switches)){
			return $modify_switches;			//return new array
		}
	}

	/*
	returns array of switch names that need to be removed from the e911 appliance (inactive in Network Management Platform)
	/**/
	public function switches_to_remove(){
		//compare E911 switches to NM Switches, return the differences
		$switchestoremove = array_keys(array_diff_key($this->E911_SWITCHES,$this->NM_SWITCHES));
		
		if (!empty($switchestoremove)){
			return $switchestoremove;		//return array of switch names
		}
	}

	/*
	add all erls that are returned from erls_to_add()
	/**/
	public function add_erls(){
		$this->logmsg .= "***ADD_ERLS*** ";
		global $DB;
		$adderls = $this->erls_to_add();			//get our list of ERLS that need to be added
		if ($adderls){								//if there are any ERLs that need to be added
			//setup our E911 api call
			$EGW = new \EmergencyGateway\EGW(	E911_ERL_SOAP_URL,
												E911_ERL_SOAP_WSDL,
												E911_SOAP_USER,
												E911_SOAP_PASS);

			foreach($adderls as $locname){		//loop through erls that need to be added
				$starttime = date('Y/m/d H:i:s');
				unset($RESULT);
				//print "ERL to ADD: " . $locname . "\n";
				unset($erlelinid);
				unset($ELINS);
				if($this->SNOW_LOCS[$locname][country] == "CAN"){
					foreach($this->NM_ELINS as $elinid => $elin){		//loop through all elins
						if ($elin[name] == $locname){					//if an elin exists with the name of the erl
							//print "Matches ELIN: " . $elin[id] . "\n";
							$erlelinid = $elin[id];							//assign elin ID to variable for later use
							break;											//no need to loop any further
						}
					}
					if($erlelinid){									//if we found an existing elin assigned to this erl
						//print "We found a matching ELIN! \n";
						$ELINS = $this->NM_ELINS[$erlelinid][number];		//assign the elin number to the EGW adderl call.
					} else {										//if no existing elin is assigned to this erl
						//assign a new elin in NM DB
						//print "No matching ELINs, we are going to find an available elin \n";
						foreach($this->NM_ELINS as $elinid => $elin){	//loop through all elins
							if ($elin[name] == "Available"){			//find the first elin called "Available"
								//print "ELIN " . $elin[id] . " is Available! assigned erl \n";
								$DID = \Information::retrieve($elinid);		//grab the elin from NM
								$DID->data['name'] = $locname;				//modify the name to erl name
								$DID->update();								//save to DB
								$this->NM_ELINS[$elinid][name] = $locname;
								$ELINS = $elin[number];
								break;									//no need to loop through elins any further
							}
						}
					}
				}
				try{
					//send the address information through the Address class to parse out house number and street name
					$ADDRESS = \EmergencyGateway\Address::fromString($this->SNOW_LOCS[$locname][street], $this->SNOW_LOCS[$locname][city], $this->SNOW_LOCS[$locname][state], $this->SNOW_LOCS[$locname][country], $this->SNOW_LOCS[$locname][zip], "Kiewit");
					//add the STREET2 information
					$ADDRESS->LOC = $this->SNOW_LOCS[$locname][u_street_2];
					//hit the EGW api to attempt to add the erl
					$RESULT = $EGW->addERL($this->SNOW_LOCS[$locname][name], (array) $ADDRESS, $ELINS);
				} catch (\Exception $e) {
					$this->logmsg .= "[{$this->SNOW_LOCS[$locname][name]}] failed with exception: {$e->getMessage()}, ";
				}
				$endtime = date('Y/m/d H:i:s');
				//LOG a successful automation to the automation log API
				if($RESULT){
					$this->logmsg .= "[{$this->SNOW_LOCS[$locname][name]}] succeeded! " . $RESULT;
					$params = [	"timesaved"			=>	"5",
								"datestarted"		=>	$starttime,
								"datefinished"		=>	$endtime,
								"description"		=>	"E911_EGW_add_erls: {$this->SNOW_LOCS[$locname][name]}",	//optional
								"notes"				=>	"Added new ERL {$this->SNOW_LOCS[$locname][name]} to the E911_EGW.",													//optional
					];
					$this->automation_report($params);
				}
			}
		}
	}

	/*
	modify all erls that are returned from erls_to_modify()
	/**/
	public function modify_erls(){
		$this->logmsg .= "***MODIFY_ERLS***";
		global $DB;
		$moderls = $this->erls_to_modify();		//get our list of erl names that need to be modified
		if ($moderls){							//if we have anything in our list
			//setup the EGW api call
			$EGW = new \EmergencyGateway\EGW(	E911_ERL_SOAP_URL,
												E911_ERL_SOAP_WSDL,
												E911_SOAP_USER,
												E911_SOAP_PASS);

			foreach($moderls as $locname){		//loop through erls that need to be added
				$starttime = date('Y/m/d H:i:s');
				//print "ERL to MODIFY: " . $locname . "\n";
				unset($RESULT);
				unset($erlelinid);
				unset($ELINS);
					foreach($this->NM_ELINS as $elinid => $elin){		//loop through all elins
						if ($elin[name] == $locname){					//if an elin exists with the name of the erl
							//print "Matches ELIN: " . $elin[id] . "\n";
							$erlelinid = $elin[id];							//assign elin ID to variable for later use
							break;											//no need to loop any further
						}
					}
					if($erlelinid){									//if we found an existing elin assigned to this erl
						//print "We found a matching ELIN! \n";
						$ELINS = $this->NM_ELINS[$erlelinid][number];		//assign the elin number to the EGW adderl call.
					} elseif ($this->SNOW_LOCS[$locname][country] == "CAN"){										//if no existing elin is assigned to this erl
						//assign a new elin in NM DB
						//print "No matching ELINs, we are going to find an available elin \n";
						foreach($this->NM_ELINS as $elinid => $elin){	//loop through all elins
							if ($elin[name] == "Available"){			//find the first elin called "Available"
								//print "ELIN " . $elin[id] . " is Available! assigned erl \n";
								$DID = \Information::retrieve($elinid);		//grab the elin from NM
								$DID->data['name'] = $locname;				//modify the name to erl name
								$DID->update();								//save to DB
								$this->NM_ELINS[$elinid][name] = $locname;
								$ELINS = $elin[number];
								break;									//no need to loop through elins any further
							}
						}
					} else {
						$ELINS = "";
					}

				//print_r($this->SNOW_LOCS[$locname]);
				//feed our address information into the Address class to parse house number and street address
				try{
					$ADDRESS = \EmergencyGateway\Address::fromString($this->SNOW_LOCS[$locname][street], $this->SNOW_LOCS[$locname][city], $this->SNOW_LOCS[$locname][state], $this->SNOW_LOCS[$locname][country], $this->SNOW_LOCS[$locname][zip], $this->SNOW_LOCS[$locname][name]);
					//add STREET2 information
					$ADDRESS->LOC = $this->SNOW_LOCS[$locname][u_street_2];
					//attempt to update this erl using the addERL function in the EGW API
					$RESULT = $EGW->addERL($this->SNOW_LOCS[$locname][name], (array) $ADDRESS, $ELINS);
				} catch (\Exception $e) {
					$this->logmsg .= "[{$this->SNOW_LOCS[$locname][name]}] failed with exception: {$e->getMessage()}, ";
				}
				$endtime = date('Y/m/d H:i:s');
				//LOG a successful automation to the automation log API
				if($RESULT){
					$this->logmsg .= "[{$this->SNOW_LOCS[$locname][name]}] succeeded! ";
					$params = [	"timesaved"			=>	"5",
								"datestarted"		=>	$starttime,
								"datefinished"		=>	$endtime,
								"description"		=>	"E911_EGWSYNC_modify_erls: {$this->SNOW_LOCS[$locname][name]}",	//optional
								"notes"				=>	"Modified ERL {$this->SNOW_LOCS[$locname][name]} in the E911_EGW.",													//optional
					];
					$this->automation_report($params);
				}
			}
		}
	}

	/*
	remove all erls that are returned from erls_to_remove()
	/**/
	public function remove_erls(){
		$this->logmsg .= "***REMOVE_ERLS***";
		global $DB;
		$remerls = $this->erls_to_remove();			//get our list of erl names that need to be removed
		if($remerls){								//if the list is not empty
			//setup our EGW API call
			$EGW = new \EmergencyGateway\EGW(	E911_ERL_SOAP_URL,
												E911_ERL_SOAP_WSDL,
												E911_SOAP_USER,
												E911_SOAP_PASS);
			foreach($remerls as $erlname){		//loop through erl names
				$starttime = date('Y/m/d H:i:s');
				//print $erlname;
				//hit the EGW API and attempt to delete the erl
				try{
					$RESULT = $EGW->deleteERL($erlname);
				} catch (\Exception $e) {
					$this->logmsg .= "[{$erlname}] failed with exception: {$e->getMessage()}, ";
				}
				$endtime = date('Y/m/d H:i:s');
				//LOG a successful automation to the automation log API
				if($RESULT){
					$this->logmsg .= "[{$erlname}] succeeded! ";
					$params = [	"timesaved"			=>	"5",
								"datestarted"		=>	$starttime,
								"datefinished"		=>	$endtime,
								"description"		=>	"E911_EGWSYNC_remove_erls: {$erlname}",	//optional
								"notes"				=>	"Removed ERL {$erlname} from the E911_EGW.",													//optional
					];
					$this->automation_report($params);
				}
			}
		}
	}

	/*
	add all switches that are returned from switches_to_add()
	/**/
	public function add_switches(){
		$this->logmsg .= "***ADD_SWITCHES***";
		global $DB;		
		$addswitches = $this->switches_to_add();		//get a list of switchnames that need to be added
		if($addswitches){								//if our list is not empty
			//setup the EGW API call
			$EGW = new \EmergencyGateway\EGW(	E911_SWITCH_SOAP_URL,
												E911_SWITCH_SOAP_WSDL,
												E911_SOAP_USER,
												E911_SOAP_PASS);

			foreach($addswitches as $switchname){				//loop through switches
				$starttime = date('Y/m/d H:i:s');
				//print "SWITCH: " . $switchname . " \n";
				//build our EGW API paremeters array for the add_switch function
				$ADD_SWITCH = array("switch_ip"				=>	$this->NM_SWITCHES[$switchname][ip],
									"switch_vendor"			=>	"Cisco",
									"switch_erl"			=>	$this->NM_SWITCHES[$switchname][snmploc][erl],
									"switch_description"	=>	$this->NM_SWITCHES[$switchname][name],);
				//attempt to add the switch
				try {
					$RESULT = $EGW->add_switch($ADD_SWITCH);
					//print_r($RESULT);
				} catch (\Exception $e) {
					$this->logmsg .= "[{$switchname}] failed with exception: {$e->getMessage()} ";
				}
				$endtime = date('Y/m/d H:i:s');
				//LOG a successful automation to the automation log API
				if($RESULT){
					$this->logmsg .= "[{$switchname}] succeeded! ";
					$params = [	"timesaved"			=>	"5",
								"datestarted"		=>	$starttime,
								"datefinished"		=>	$endtime,
								"description"		=>	"E911_EGWSYNC_add_switches: {$switchname}",	//optional
								"notes"				=>	"Added switch {$switchname} to the E911_EGW.",													//optional
					];
					$this->automation_report($params);
				}
			}
		}
	}

	/*
	modify all switches that are returned from switches_to_modify()
	/**/
	public function modify_switches(){
		$this->logmsg .= "***MODIFY_SWITCHES***";
		global $DB;
		$modswitches = $this->switches_to_modify();				//get our list of switch names that need to be modified
		if($modswitches){										//if the list is not empty
			//setup our EGW API call
			$EGW = new \EmergencyGateway\EGW(	E911_SWITCH_SOAP_URL,
												E911_SWITCH_SOAP_WSDL,
												E911_SOAP_USER,
												E911_SOAP_PASS);

			foreach($modswitches as $switchname){		//loop through the switches
				unset($RESULT1);
				unset($RESULT2);
				$starttime = date('Y/m/d H:i:s');
				//setup our EGW parameters
                $ADD_SWITCH = array(
					"switch_ip"             =>  $this->NM_SWITCHES[$switchname][ip],
					"switch_vendor"         =>  "Cisco",
					"switch_erl"            =>  $this->NM_SWITCHES[$switchname][snmploc][erl],
					"switch_description"    =>  $this->NM_SWITCHES[$switchname][name],
				);
				//attempt to update the switch via the EGW api
				try {
					if ($RESULT1 = $EGW->delete_switch($this->E911_SWITCHES[$switchname][ip])){

						if(!$RESULT2 = $EGW->add_switch($ADD_SWITCH)){
							throw new Exception("Failed to add new switch " . $switchname . " with message " . $RESULT2);
						}
					} else {
						throw new Exception("Failed to remove switch " . $switchname . " with message " . $RESULT1);
					}
				} catch (\Exception $e) {
					$this->logmsg .= "[{$switchname}] failed with exception: {$e->getMessage()}. ";
				}
				$endtime = date('Y/m/d H:i:s');
				//LOG a successful automation to the automation log API
				if($RESULT){
					$this->logmsg .= "[{$switchname}] succeeded! ";
					$params = [	"timesaved"			=>	"5",
								"datestarted"		=>	$starttime,
								"datefinished"		=>	$endtime,
								"description"		=>	"E911_EGWSYNC_modify_switches: {$switchname}",	//optional
								"notes"				=>	"Modified switch {$switchname} in the E911_EGW.",													//optional
					];
					$this->automation_report($params);
				}
			}
		}
	}

	/*
	remove all switches that are returned from switches_to_remove()
	/**/
	public function remove_switches(){
		$this->logmsg .= "***REMOVE_SWITCHES***";
		global $DB;
		$remswitches = $this->switches_to_remove();				//get our list of switches that need to be removed
		//print_r($remswitches);
		if($remswitches){										//if our list is not empty
			//setup our EGW API call
			$EGW = new \EmergencyGateway\EGW(	E911_SWITCH_SOAP_URL,
												E911_SWITCH_SOAP_WSDL,
												E911_SOAP_USER,
												E911_SOAP_PASS);

			foreach($remswitches as $switchname){		//loop through switches
				$starttime = date('Y/m/d H:i:s');
				//attempt to delete the switch through EGW API
				try {
					$RESULT = $EGW->delete_switch($this->E911_SWITCHES[$switchname][ip]);
				} catch (\Exception $e) {
					$this->logmsg .= "[{$this->E911_SWITCHES[$switchname][name]}] failed with exception: {$e->getMessage()}, ";
				}
				$endtime = date('Y/m/d H:i:s');
				//LOG a successful automation to the automation log API
				if($RESULT){
					$this->logmsg .= "[{$this->E911_SWITCHES[$switchname][name]}] succeeded! ";
					$params = [	"timesaved"			=>	"5",
								"datestarted"		=>	$starttime,
								"datefinished"		=>	$endtime,
								"description"		=>	"E911_EGWSYNC_remove_switches: {$switchname}",	//optional
								"notes"				=>	"Removed switch {$switchname} from the E911_EGW.",													//optional
					];
					$this->automation_report($params);
				}
			}
		}
	}
}
