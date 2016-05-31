# EGWsync is designed to take a source of network switches, location addresses, and ELIN phone numbers and auto populate an EGW appliance and keep it updated.

The source of network switches in this library is a proprietary network management system but could easily be modified to access data from any source.

The source of location addresses is accessed via a RESTful api.

The source of ELIN phone numbers is accessed via private database.

Simply modify the sources of data, convert to proper format, and let er rip.

The following functions GATHER the data and compile lists of ERL/SWITCHES that need to be ADD/REMOVED/MODIFIED:
        public function erls_to_add(){
        public function erls_to_remove(){
        public function erls_to_modify(){
        public function switches_to_add(){
        public function switches_to_modify(){
        public function switches_to_remove(){
        
and the following functions perform those tasks :
        public function add_erls(){
        public function modify_erls(){
        public function remove_erls(){
        public function add_switches(){
        public function modify_switches(){
        public function remove_switches(){

