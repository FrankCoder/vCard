<?php
//
// Made available under the MIT license 
// (https://opensource.org/license/mit/)
// ========================================================================
// © 2023 Jean-François Davignon
// Permission is hereby granted, free of charge, to any person obtaining a 
// copy of this software and associated documentation files 
// (the “Software”), to deal in the Software without restriction, including 
// without limitation the rights to use, copy, modify, merge, publish, 
// distribute, sublicense, and/or sell copies of the Software, and to 
// permit persons to whom the Software is furnished to do so, subject to 
// the following conditions:
//
// The above copyright notice and this permission notice shall be included
// in all copies or substantial portions of the Software.
//
// THE SOFTWARE IS PROVIDED “AS IS”, WITHOUT WARRANTY OF ANY KIND, EXPRESS 
// OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF 
// MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. 
// IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY 
// CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, 
// TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE 
// SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
// ========================================================================
//
// This is a standalone vCard version 4.0 object model
// It can parse previous versions but only creates v4.0 vCards
//
// When parsing, there is little validating being done to accomodate for
// obsolete properties, parameters, and their values. 
// A better implementation might be to build validation for past versions,
// whether it's worth the effort is debatable.
//
// It makes no attempts at validating or accepting new iana registered
// vCard elements, language tags, or mime types, including vendor specific 
// ones. These can be added in the proper validation array constants 
// defined in the VCProperty class, or as new regular expression 
// validations. Please add appropriate tests in vcf.test.php
//
// There is a fair amount of validation when building vCards for v4 
// compliance but there is no detailed value validation for a number of 
// properties (e.g. XML, MEDIATYPE, ...) and parameters. See the VCProperty
// for implementation details.
//
// USAGE
// To load vCards from a file:
// $vCards = VCCardCollection::createFromFile("path/to/cardfile.vcf");
// foreach($vCards as $card){
//     echo implode(", ", $card->propertyNames());   
// }
// for($i = 0, $z = $vCards->count(); $i < $z; ++$i){
//     echo $vCards[$i]['FN']->value;
// }
// See the vcf.test.php for more usage hints
// ========================================================================

/**
 * Represents a generic property.
 * 
 * Can parse any property into property group, name, value, and parameters.
 * 
 * Provides v4 compliance checks when building vCards. 
 */
class VCProperty implements Stringable{
    /**
     * Holds the group identifier if any
     * @var string $group
     */
    public string $group = '';
    /**
     * Holds the name of the vCard property (e.g. 'ADR' or 'BEGIN')
     * @var string $name
     */
    public string $name = '';
    /**
     * Holds the value of vCard property (e.g., ';;123 Main Street;Any Town;CA;91921-1234;U.S.A.' or 'VCARD')
     * @var string $value
     */
    public string $value = '';
    
    /**
     * Accepts string or VCProperty, default does nothing.
     * 
     * The string constructor expects an unfolded content line that represents
     * an entire VCARD property per rfc6350 or the BEGIN, VERSION, or END tag lines.
     * Also accepts IANA properties and X- experimental properties as per rfc6350.
     * 
     * The copy constructor is
     * for use when a property has a corresponding sub class of VCProperty
     * that further provides accessors to the elements that compose the value
     * of the property (e.g., ADR which has a VCAddress sub class).
     * @param string|VCProperty|null $prop 
     * @return void 
     * @throws Exception if passed-in string property is not parsable per rfc6350 (https://www.rfc-editor.org/rfc/rfc6350)
     */
    public function __construct(string|VCProperty|null $prop = null){
        // Default constructor
        if($prop === null){ return; }
        // Parsing constructor
        if(is_string($prop)){
            $this->_parse($prop);
            return;
        }
        // Assignment constructor
        $this->name = $prop->name;
        $this->group = $prop->group;
        $this->value = $prop->value;
        $this->_parameters = $prop->_parameters;
    }
    /**
     * Create VCProperty using name and value strings
     * @param string $propName 
     * @param string $propValue 
     * @return VCProperty 
     * @throws BadMethodCallException when passing in an unrecognized property name for vCard v4.
     */
    public static function create(string $propName, string $propValue): VCProperty{
        $propName = mb_strtoupper($propName);
        $result = preg_match(self::_x_name_re, $propName, $match);
        if(array_search($propName, self::_valid_v4_properties) === false && !$result ){
            throw new BadMethodCallException("VCProperty::create() - Unrecognized property '".$propName."' for vCard version 4.0");
        }
        if($propName === 'KIND' && array_search($propValue, self::_kind_property_values) === false){
            throw new BadMethodCallException("VCProperty::create() - Value '".$propValue."' is invalid for property 'KIND' in vCard version 4.0");
        }
        $prop = new VCProperty();
        $prop->name = $propName;
        $prop->value = $propValue;
        return $prop;
    }
    /**
     * Indicates whether this property is unrecognized and thus unsupported by this implementation or not.
     * @return bool 
     */
    public function v4_unsupportedProperty(): bool{
        return $this->_notAv4Property;
    }
    /**
     * Retrieves value for given parameter
     * 
     * For parameters that have a * cardinality (can have multiple values) 
     * may return multiple values separated by a COMMA (e.g., 'HOME,POSTAL,pref' for the TYPE)
     * @param string $parameter 
     * @return string|int|false returns false if parameter not present, an integer if parameter name is 'pref'
     */
    public function getParameterValue(string $parameter): string|int|false{
        if(key_exists($parameter, $this->_parameters)){
            if($parameter === 'pref'){
                return (int)$this->_parameters[$parameter];
            }
            return self::_decode_param_value($this->_parameters[$parameter]);
        }
        return false;
    }
    /**
     * Sets parameter with value or appends value if parameter already exists.
     * @param string $key 
     * @param string $value 
     * @return bool returns false if parameter is not recognize or it can't be applied to this property.
     */
    public function setParameter(string $key, string $value): bool{
        $key = mb_strtolower($key);
        switch($key){
            case 'type':
// type-value must be one of "work" / "home" / type-param-tel / type-param-related / iana-token / x-name
                if(array_search($this->name, self::_type_param_all) === false){
                    return false; // 'type' param not allowed for this property
                }
                if($this->name != 'TEL' && array_search($value, self::_type_param_tel) !== false){
                    return false; // Value is only valid for property 'TEL'
                }
                if($this->name != 'RELATED' && array_search($value, self::_type_param_related) !== false){
                    return false; // Value is only valid for property 'RELATED'
                }
                if(array_search($value, ['work','home']) === false){
                    if(!preg_match(self::_x_name_re, $value)){
                        return false;
                    }
                }
                $this->_setParameter($key, $value);
                return true;
            case 'value':
                $result = preg_match(self::_value_param_re, $value, $match);
                if(!$result){ return false; }
                $this->_setParameter($key, $value);
                return true;
            case 'pref':
                $result = preg_match("/[1-9][0-9]?/", $value, $match);
                if(!$result){ return false; }
                $this->_setParameter($key, $value);
                return true;
            case 'pid':
                $result = preg_match("/[0-9](?:\.[0-9])?/", $value, $match);
                if(!$result){ return false; }
                $this->_setParameter($key, $value);
                return true;
            case 'calscale':
                if($this->name !== 'BDAY' && $this->name !== 'ANNIVERSARY'){
                    return false;
                }
                $result = preg_match(self::_calscale_param_re, $value, $match);
                if(!$result){ return false; }
                $this->_setParameter($key, $value);
                return true;
            case 'sort-as':
                $result = preg_match('/(?:[^\r\n\t\v\a":;]+)|(?:"[^\r\n\t\v\a]+")/', $value, $match);
                if(!$result){ return false; }
                $this->_setParameter($key, $value);
                return true;
            case 'geo':
                $result = preg_match('/(?:"[a-zA-Z]:[\/a-zA-Z\.]+(?:\?)")/', $value, $match);
                if(!$result){ return false; }
                $this->_setParameter($key, $value);
                return true;
            case 'mediatype':
                $result = preg_match('/[a-zA-Z]+\/[a-zA-Z]+/', $value, $match);
                if(!$result){ return false; }
                $this->_setParameter($key, $value);
                return true;
            case 'language':
            case 'altid':
            case 'type':
            case 'tz':
                $this->_setParameter($key, $value);
                return true;
            default:
                break;
        }
        $result = preg_match(self::_x_name_re, $key, $match); // match x-name
        if($result === false){
            throw new UnhandledMatchError("VCProperty::setParameter() - Unexpected error validating parameter name.");
        }
        if($result === 0){
            return false;
        }
        $this->_setParameter($key, $value);
        return true;
    }
    /**
     * Checks if a TYPE parameter with $value exists
     * @param string $value 
     * @return bool 
     */
    public function hasTypeValue(string $value): bool{
        return key_exists('type', $this->_parameters) && mb_stripos($this->_parameters['type'], $value) !== false;
    }
    /**
     * If v4 parameter 'pref' is found returns value as an int, 
     * if v3 parameter 'type' has a value of 'pref' it returns 1,
     * otherwise it returns 0.
     * @return int preference level as per rfc6350
     */
    public function pref(): int{
        if(key_exists('pref', $this->_parameters)){
            return (int) $this->_parameters['pref'];
        }
        if($this->hasTypeValue('pref')){
            return 1;
        }
        return 0;
    }
    /**
     * Helper function for logging in a helpful way
     * @return string 
     */
    public function toJSON(){
        return "[".json_encode($this, JSON_PRETTY_PRINT).", <br>".json_encode($this->_parameters, JSON_PRETTY_PRINT)."]";
    }
    /**
     * returns this VCProperty as a vCard property string
     * @return string 
     */
    public function __toString(){
        $string = '';
        if($this->group !== ''){ 
            $string .= $this->group.'.'; 
        }
        $string .= $this->name . (count($this->_parameters) > 0 ? $this->_parametersString().":".$this->value : ":".$this->value);
        return trim(self::_fold($string));
    }
    /**
     * PRIVATE: sets or appends value to given parameter
     * @param string $key 
     * @param string $value 
     * @return void 
     */
    private function _setParameter(string $key, string $value): void{
        if(key_exists($key, $this->_parameters)){
            $this->_parameters[$key] .= ','.self::_encode_param_value($value);
        }else{
            $this->_parameters[$key] = self::_encode_param_value($value);
        }
    }
    /**
     * PRIVATE: Helper function that reconstructs the paramters as a string for saving to file
     * @return string 
     */
    private function _parametersString(): string{
        $params = "";
        foreach($this->_parameters as $k => $v){
            if(str_starts_with($v, "\"") === false && preg_match("/.*?(?<!\\\\),.*/", $v)){
                $pvalues = preg_split("/(?<!\\\\),/", $v);
                foreach($pvalues as $pv){
                    $params .= ";".$k."=".$pv;
                }
            }
            else{
                $params .= ";".$k."=".$v;
            }
        }
        return $params;
    }
    /**
     * PRIVATE: Parses a VCARD property into group, name, value, and parameters
     * @param string $prop 
     * @return void 
     * @throws Exception 
     * @throws UnexpectedValueException 
     */
    private function _parse(string $prop){
        $num = preg_match(self::_prop_re, $prop, $matches, PREG_OFFSET_CAPTURE);
        if($num === false){
            throw new Exception("Unextpected error thrown while parsing vCard property string");
        }
        if($num === 0){
            throw new UnexpectedValueException("Not a vCard property string");
        }
        if(key_exists('grp', $matches) && $matches['grp'][1] >= 0){
            $this->group = $matches['grp'][0];
        }
        if(key_exists('name', $matches) && $matches['name'][1] >= 0){
            $this->name = mb_strtoupper($matches['name'][0]);
            if(array_search($this->name, self::_valid_v4_properties) === false){
                $this->_notAv4Property = true;
            }
        }
        else{ // if no property name, bail!
            $this->name = "UNKOWN_NAME";
            $this->value = "UNKNOWN_VALUE"; // storing for logging
            // error_log("VCProperty::_parse() - Unable to parse as a vCard property: ".$prop);
            $this->_notAv4Property = true;
            return;
        }
        if(key_exists('params', $matches) && $matches['params'][1] >= 0){
            preg_match_all(self::_param_re, $matches['params'][0], $matches2, PREG_SET_ORDER);
            foreach($matches2 as $match){
                $key = mb_strtolower($match[1]);
                if(key_exists($key, $this->_parameters)){
                    $this->_parameters[$key] .= ','.$match[2];
                }
                else{
                    $this->_parameters[$key] = $match[2];
                }
            }
        }
        if(!key_exists('val', $matches) || $matches['val'][1] < 0){ // if no remainder, bail!
            $this->value = "UNKNOWN_VALUE"; // storing for logging
            // error_log("VCProperty::_parse() - Unable to parse as a vCard property: ".$prop);
            $this->_notAv4Property = true;
            return;
        }
        $this->value = $matches['val'][0];
    }
    /**
     * Useful for determining method to fold long lines
     * @param string $string 
     * @return bool 
     */
    private static function _is_ascii(string $string = ''): bool
    {
        $num = 0;
        while (isset($string[$num])) {
            if (ord($string[$num]) & 0x80) {
                return false;
            }
            $num++;
        }
        return true;
    }
    /**
     * Helper function that folds ascii strings.
     * Less of a memory hog than the _fold_utf8() mmethod
     * @param string $propertyString 
     * @return string 
     */
    private static function _fold_ascii(string $propertyString): string{
        return chunk_split($propertyString, 75, "\r\n ");
    }
    /**
     * Helper function that folds utf8 strings
     * @param string $propertyString 
     * @return string 
     */
    private static function _fold_utf8(string $propertyString): string
    {
        $array = array_chunk( preg_split("//u", $propertyString, -1, PREG_SPLIT_NO_EMPTY), 75);
        $propertyString = "";
        foreach ($array as $item) {
            $propertyString .= $item."\r\n ";
        }
        return $propertyString;
    }
    /**
     * Performs line folding per rfc6350
     * @param string $propertySring 
     * @return string 
     */
    private static function _fold(string $propertySring): string{
        if(mb_strlen($propertySring) <= 75){
            return $propertySring;
        }
        if(self::_is_ascii($propertySring)){
            return self::_fold_ascii($propertySring);
        }
        return self::_fold_utf8($propertySring);
    }
    /**
     * Used for encoding multiline values per rfc6350 section 3.4 (https://www.rfc-editor.org/rfc/rfc6350#section-3.4)
     * @param string $text 
     * @return string 
     */
    private static function _encode_param_value(string $text): string{
        $text = preg_replace('/(?:\r\n)|\r|\n/', '\\n', $text);
        $text = preg_replace('/\t/', '\\t', $text);
        if(mb_strpos($text, "\"") !== 0){// If DQUOTE string DQUOTE encode commas and semi-colons.
            $text = preg_replace('/,/', '\\,', $text);
            $text = preg_replace('/;/', '\\;', $text);
        }
        return $text;
    }
    private static function _decode_param_value(string $text): string{
        if(mb_strpos($text, "\"") !== 0){// If DQUOTE string DQUOTE encode commas and semi-colons.
            $text = preg_replace('/\\\\;/', ';', $text);
            $text = preg_replace('/\\\\,/', ',', $text);
        }
        $text = preg_replace('/\\\\t/', '\t', $text);
        $text = preg_replace('/\\\\n/', '\n', $text);
        return $text;
    }
    protected array $_parameters = [];
    // If strict v4 property parsing needed, the following regular expression can be used. To support known iana-tokens, these
    // must be added to the regex. For this implementation we rely on the _valid_v4_properties array below to perform property
    // valisdation.
    //
    // Regex for parsing v4.0 vCard property string (e.g.; priates.FN;value=text:Jack B. Sparrow)
    //                   case insensitive                             captures name of property
    //                   \_____    _____/ captures group if any       \_______captures version 4 VCARD property names______________________________________________________________________________________________________________________________________________________________________________________________x-name______________/ captures parameters if any                               value
    //                         \__/       \__________________________/        \__________________________________________________________________________________________________________________________________________________________________________________________________________________________________/ \___________________/ \_____________________________________________________/  \________/
    // private const _prop_v4_re = '/(?i)^\s*(?:(?<grp>[a-zA-Z0-9-]+)\.)?(?<name>(?:BEGIN|VERSION|END|SOURCE|KIND|FN|N|NICKNAME|PHOTO|BDAY|ANNIVERSARY|GENDER|ADR|TEL|EMAIL|IMPP|LANG|TZ|GEO|TITLE|ROLE|LOGO|ORG|MEMBER|RELATED|CATEGORIES|NOTE|PRODID|REV|SOUND|UID|CLIENTPIDMAP|URL|KEY|FBURL|CALADRURI|CALURI|XML)|(?:[xX]-[a-zA-Z0-9-]+))(?<params>(?:;[a-zA-Z]+=(?:(?:"[^"]+")|(?:[^;\:]+)))*)\:(?<val>.+)$/';

    // Regex for parsing vCard property string (no property validation)
    //                   case insensitive                          
    //                   \_____    _____/ captures group if any    captures name of property       captures parameters if any                              value
    //                         \__/    \__________________________/\______________________________/\____________________________________________________/  \________/
    private const _prop_re = '/(?i)^\s*(?:(?<grp>[a-zA-Z0-9-]+)\.)?(?<name>(?:[xX]-)?[a-zA-Z0-9-]+)(?<params>(?:;[a-zA-Z]+=(?:(?:"[^"]+")|(?:[^;:]+)))*)\:(?<val>.+)$/';
    //
    // Regular expresssions to validate the value of certain parameters and properties
    // pre rfc6350
    //
    private const _value_param_re = "/^text|uri|date|time|date-time|date-and-or-time|timestamp|boolean|integer|float|utc-offset|language-tag|(?:[Xx]-[a-zA-Z0-9-]+)$/";
    private const _calscale_param_re = "/^gregorian|(?:[xX]-[a-zA-Z0-9-]+)$/";
    private const _x_name_re = "/^[xX]-[a-zA-Z-]+$/";
    private const _param_re = '/;(?i)(language|value|pref|altid|pid|type|mediatype|calscale|sort-as|geo|tz|(?:(?:[x]-)?[a-zA-Z-]+))=((?:"[^"]+")|(?:[^\r\n\t\v\a";:]+))/';
    private bool $_notAv4Property = false;
    /**
     * vCard v4.0 valid properties.
     * To support new (or exisitng) iana-tokens, add them to this list.
     * 
     * Note: While the rfc does not include BEGIN, VERSION, and END as property
     * names in the abnf description, they are listed under section 6.1 General Properties
     * (https://www.rfc-editor.org/rfc/rfc6350#section-6.1). Including them in this
     * validation list instead or treating them separately or individualy makes for 
     * simpler parsing code.
     */
    private const _valid_v4_properties = [
        "BEGIN", "VERSION", "END", "SOURCE", "KIND", "FN", "N", "NICKNAME"
        , "PHOTO", "BDAY", "ANNIVERSARY", "GENDER", "ADR", "TEL"
        , "EMAIL", "IMPP", "LANG", "TZ", "GEO", "TITLE", "ROLE"
        , "LOGO", "ORG", "MEMBER", "RELATED", "CATEGORIES"
        , "NOTE", "PRODID", "REV", "SOUND", "UID", "CLIENTPIDMAP"
        , "URL", "KEY", "FBURL", "CALADRURI", "CALURI", "XML"];
    /**
     * vCard v4.0 valid values for KIND property
     */
    private const _kind_property_values = ['individual','group','org','location'];
    /**
     * vCard v4.0 properties that can have the 'type' parameter
     */
    private const _type_param_all = [
        'FN', 'NICKNAME', 'PHOTO', 'ADR', 'TEL', 'EMAIL'
        , 'IMPP', 'LANG', 'TZ', 'GEO', 'TITLE', 'ROLE', 'LOGO'
        , 'ORG', 'RELATED', 'CATEGORIES', 'NOTE', 'SOUND', 'URL'
        , 'KEY', 'FBURL', 'CALADRURI', 'CALURI'];
    /**
     * vCard v4.0 'type' parameter values that are only allowed on 'TEL' property
     */
    private const _type_param_tel = [
        "text", "voice", "fax", "cell", "video", "pager", "textphone"];
    /**
     * vCard v4.0 'type' parameter values that are only allowed on 'RELATED' property
     */
    private const _type_param_related = ["contact", "acquaintance", "friend", "met"
        , "co-worker", "colleague", "co-resident" , "neighbor", "child", "parent"
        , "sibling", "spouse", "kin", "muse" , "crush", "date", "sweetheart", "me"
        , "agent", "emergency"];
}
/**
 * Represents an 'ADR' property.
 * Can be constructed using the string representation of a vCard property or
 * by passing an existing VCProperty object that contains an 'ADR' property
 * @package 
 */
class VCAddress extends VCProperty{
    // components of ADR value as defined in rfc6350 (https://www.rfc-editor.org/rfc/rfc6350)
    public string $po_box = '';
    public string $extended = '';
    public string $street = '';
    public string $city = '';
    public string $region = '';
    public string $zip = '';
    public string $country = '';
    /**
     * Constructor for VCAddress.
     * Can be constructed using the string representation of a vCard content line for
     * property 'ADR' or by passing an existing VCProperty object that contains an 'ADR' property
     * @param string|VCProperty $prop 
     * @return void 
     * @throws Exception 
     * @throws UnexpectedValueException 
     */
    public function __construct(string|VCProperty $prop)
    {
        parent::__construct($prop);
        if($this->name !== 'ADR'){
            throw new UnexpectedValueException("VCProperty::__construct() Expecting ADR got $this->name");
        }   
        @list( $this->po_box, $this->extended, $this->street, $this->city, $this->region, $this->zip, $this->country,) = explode(';', $this->value);
    }
    /**
     * Creates a new VCAddress from postal address components
     * @param string $street 
     * @param string $city 
     * @param string $region 
     * @param string $zip 
     * @param string $country 
     * @param string $po_box optional
     * @param string $extended optional
     * @return VCAddress 
     */
    public static function createADR( string $street, string $city, string $region, string $zip, string $country, string $po_box = '', string $extended = '',): VCAddress{
        return new VCAddress("ADR:$po_box;$extended;$street;$city;$region;$zip;$country");
    }
}
/**
     * Represent VCARD property 'N'.
     * Can be constructed using the string representation of a vCard content line for
     * property 'N' or by passing an existing VCProperty object that contains an 'N' property
 * @package 
 */
class VCN extends VCProperty{
    public string $surname = '';
    public string $given = '';
    public string $additional = '';
    public string $prefix = '';
    public string $suffix = '';
    /**
     * Constructor for VCN.
     * Can be constructed using the string representation of a vCard content line for
     * property 'N' or by passing an existing VCProperty object that contains an 'N' property
     * @param string|VCProperty $prop 
     * @return void 
     * @throws Exception 
     * @throws UnexpectedValueException 
     */
    public function __construct(string|VCProperty $prop)
    {
        parent::__construct($prop);
        if($this->name !== 'N')  {
            throw new UnexpectedValueException("VCProperty::__construct() Expecting N got $this->name");
        }
        @list($this->surname, $this->given, $this->additional, $this->prefix, $this->suffix) = explode(';', $this->value);
    }
    /**
     * Creates a new VCN fron the components of the name
     * @param string $surname 
     * @param string $given optional
     * @param string $additional optional
     * @param string $prefix optional
     * @param string $suffix optional
     * @return VCN 
     */
    public static function createN(string $surname, string $given = '', string $additional = '', string $prefix = '', string $suffix = ''): VCN{
        return new VCN("N:$surname;$given;$additional;$prefix;$suffix");
    }
}
/**
 * Represents a single vCard.
 * 
 * NON-STANDARD implementation of the ArrayAccess interface. The underlying
 * array is never associative other than a sequential 0 based index.
 * 
 * @package 
 */
class VCCard implements ArrayAccess, Iterator, Countable, Stringable{
    /**
     * Holds version of vCard as a string.
     * When created from parsing a .vcf file, is set to version of parsed vCard.
     * @var string
     */
    public string $version = '4.0';
    /**
     * Creates a VCCard.
     * Default constructor does nothing. Parsing constructor creates the
     * VCCard from a '/(?s)(BEGIN:VCARD.+?END:VCARD)/' regex match
     * @param null|string $vCardString 
     * @return void 
     * @throws RuntimeException 
     * @throws UnexpectedValueException 
     */
    public function __construct(string|null $vCardString = null){
        if($vCardString === null){ return; }
        $this->parseString($vCardString);
    }
    /**
     * Fills VCCard from a '/(?s)(BEGIN:VCARD.+?END:VCARD)/' regex match
     * @param string $vCardString 
     * @return void 
     * @throws RuntimeException 
     * @throws UnexpectedValueException 
     */
    public function parseString(string $vCardString): void{
        $result = preg_match_all('/(?<=\n)?([^\r\n]+)(?:[\r\n]+)?/', $this->_unfold($vCardString), $vCardLines);
        if($result === false){
            throw new RuntimeException("VCCard::__construct() - Unexpected error parsing vCard string.");
        }
        if($result === 0){
            throw new UnexpectedValueException('VCCard::__construct() - Failed to parse string '.$vCardString);
        }
        $firstLine = true;
        foreach($vCardLines[1] as $line){
            $prop = new VCProperty($line);
            if($firstLine){
                if($prop->name !== 'BEGIN'){
                    throw new UnexpectedValueException('VCCard::__construct() - BEGIN expected got '.$prop->name);
                }
                $firstLine = false;
            }
            switch($prop->name){
                case 'BEGIN':
                    if($prop->value !== 'VCARD'){
                        throw new UnexpectedValueException('VCCard::__construct() - BEGIN expected value VCARD got '.$prop->value);
                    }
                    break;
                case 'END':
                    return; // That should be it, bail
                case 'VERSION':
                    $this->version = $prop->value;
                    break;
                case 'ADR':
                    $this->addProperty(new VCAddress($prop));
                    break;
                case 'N':
                    $this->addProperty(new VCN($prop));
                    break;
                case 'KIND': case 'SOURCE': case 'FN': case 'NICKNAME': case 'PHOTO': case 'BDAY':
                case 'ANNIVERSARY': case 'GENDER': case 'TEL': case 'EMAIL': case 'IMPP': case 'LANG':
                case 'TZ': case 'GEO': case 'TITLE': case 'ROLE': case 'LOGO': case 'ORG':
                case 'MEMBER': case 'RELATED': case 'CATEGORIES': case 'NOTE': case 'PRODID': case 'REV':
                case 'SOUND': case 'UID': case 'CLIENTPIDMAP': case 'URL': case 'KEY': case 'FBURL':
                case 'CALADRURI': case 'CALURI': case 'XML':
                    $this->addProperty($prop);
                    break;
                default:
                    // ignore properties we don't know
                    break;
            }
        }
    }
    /**
     * Returns the numerical value of $this->version
     * @return float 
     */
    public function version(): float{
        return (float)$this->version;
    }
    /**
     * Full name of vCard (property FN with cardinality 1)
     * @return string Returns value of required property 'FN', null if called before FN added to card.
     */
    public function fn(): string|null{
        $result = $this->property('FN');
        if($result){
            return $result->value;
        }
        return null;
    }
    /**
     * Adds property to the card.
     * @param VCProperty $vcfProperty 
     * @return void 
     */
    public function addProperty(VCProperty|VCAddress|VCN $vcfProperty):void{
        $this->_properties[] = $vcfProperty;
    }
    /**
     * Retrieves property or array of properties with given name.
     * Some properties can be entered multiple times vith different values. If
     * more than one property is found, they are returned in an array.
     * @param string|int $key Position or name of property to retrieve
     * @return VCProperty|VCAddress|VCN|array|null returns null if no property with that name or position is found.
     */
    public function property(string|int $key): VCProperty|VCAddress|VCN|array|null{
        if(is_string($key)){
            $propName = mb_strtoupper($key); // Implementation forces property name to upper case
            // There may be multiple properties with the same name, like ADR or TEL, so we filter
            // the array instead of looking for first
            $props = array_filter($this->_properties, fn($v) => $v->name === $propName);
            $num = count($props);
            return $num === 0 ? null : ($num === 1 ? current($props) : $props);
        }
        return $this->_properties[$key];
    }
    /**
     * Retrieves the value for the given property.
     * 
     * If more than one property, returns the value of the property with 
     * a pref parameter of 1 or the first property in the array if pref is not set.
     * @param string $propertyname 
     * @return string empty string is returmned if property not present.
     */
    public function value(string $propertyname): string{
        $prop = $this->property($propertyname);
        if($prop === null) { return ""; }
        if(is_array($prop)){
            $result = array_filter($prop, fn($v) => $v->pref() === 1);
            if(count($result) > 0){
                return current($result)->value;
            }
            return current($prop)->value;
        }
        return $prop->value;
    }
    /**
     * Returns the count of properties
     * @return int 
     */
    public function count(): int{
        return count($this->_properties);
    }
    /**
     * Returns the names of the properties contained in this VCCard
     * @return array 
     */
    public function propertyNames(): array{
        $names = [];
        foreach($this->_properties as $property){
            $names[] = $property->name;
        }
        return array_unique($names);
    }
    /**
     * Outputs the vCard format specified by rfc6350
     * @return mixed 
     */
    public function __toString()
    {
        $cardString = "BEGIN:VCARD\r\nVERSION:".$this->version."\r\n";
        foreach($this->_properties as $property){
            $cardString .= $property."\r\n";
        }
        $cardString .= "END:VCARD\r\n";
        return $cardString;
    }
    //
    // Array access implementation
    //
    /**
     * NON-STANDARD accessor.
     * This setter ignores the key (offset) and always appends the value to the underlying array.
     * 
     * The array is never associative other than a 0 based sequential index.
     * i.e., '$vCard[/-?\d+/] = $property;' is the same as'$vCard[] = $property;' 
     * @param mixed $offset Ignored.
     * @param mixed $value 
     * @return void 
     */
    public function offsetSet(mixed $offset, mixed $value): void {
        $this->_properties[] = $value;
    }
    public function offsetExists(mixed $offset): bool {
        return isset($this->_properties[$offset]);
    }
    public function offsetUnset(mixed $offset): void {
        unset($this->_properties[$offset]);
    }
    /**
     * NON-STANDARD array access.
     * The underlying array is never associative other than a sequential 0 based key index.
     * 
     * Returns the property (ies) or the value of the given offset:
     * 
     * This get accessor calls the VCCard::value() nethod if the property name is passed as 
     * the offset and VCCard::property() method if the offset is an integer.
     * @param mixed $offset Effectively accepts an integer to retrieve a property at a given position,
     *  or the property name of which to retrieve the value. Any other type will cause the accessor to return false.
     * @return mixed In effect will return VCProperty|VCAddress|VCN|array|string|null|false or the value of the 
     * requested property.
     */
    public function offsetGet(mixed $offset): mixed {
        if(is_string($offset)){
            return $this->value($offset);
        }
        if(is_int($offset)){
            return $this->property($offset);
        }
        return false;
        // return isset($this->_properties[$offset]) ? $this->_properties[$offset] : null;
    }
    //
    // Iterator implementation
    //
    public function current(): mixed{
        return $this->_properties[$this->_position];
    }
    public function key(): mixed{
        return $this->_position;
    }
    public function next(): void{
        ++$this->_position;
    }
    public function rewind(): void{
        $this->_position = 0;
    }
    public function valid(): bool{
        return isset($this->_properties[$this->_position]);
    }
    private function _unfold(string $text): string{
        return preg_replace("/(?:(?:\r\n)|\n) /", "", $text);
    }
    private array $_properties = []; 
    private int $_position = 0;
}

/**
 * Represents a collection of vCard.
 * 
 * Use as you would an array. Note that static factory createFromFile()
 * just pushes properties onto the underlying array.  No associative
 * key is used or created.
 * 
 * Similarly, the __toString() method, which is called from the save() method,
 * simply iterates through the array to build the contents of the .vcf file.
 */
class VCCardCollection implements ArrayAccess, Iterator, Countable, Stringable{
    /**
     * Create VCCardCollection from .vcf file. 
     * 
     * Replaces any existing content or for use with Default constructor.
     * @param string $filePath 
     * @return VCCardCollection
     * @throws BadMethodCallException 
     * @throws RuntimeException 
     * @throws UnexpectedValueException 
     */
    public static function createFromFile(string $filePath): VCCardCollection{
        if(!file_exists($filePath)){
            throw new BadMethodCallException('VcfFile::createFromFile() - file does not exists: '.$filePath);
        }
        $contents = file_get_contents($filePath);
        if($contents === false){
            throw new RuntimeException("VcfFile::createFromFile() - Unexpected error loading file $filePath", -1);
        }
        $result = preg_match_all('/(?s)(BEGIN:VCARD.+?END:VCARD)/', $contents, $matches);
        if($result === false){
            throw new RuntimeException("VcfFile::createFromFile() - Unexpected error parsing contents of file $filePath", -1);
        }
        if($result === 0){
            throw new UnexpectedValueException('VCCardCollection:: createFromFile() - Unrecognized file format for file '. $filePath);
        }
        $collection = new VCCardCollection();
        foreach($matches[1] as $entry){
            $collection[] = new VCCard($entry);
        }
        return $collection;
    }
    /**
     * Save contents to file
     * @param null|string $filePath path and file name where 
     * to save the vCards. default uses previously saved filename.
     * @param bool $overwrite 
     * @return int|false number of bytes saved or returns false if the file already exists and $overwrite is false or saving to file fails 
     */
    public function save(string $filePath, bool $overwrite = false): int|false{
        if(!$filePath){
            throw new UnexpectedValueException('VCCardCollection::save() - No file path and name to save to.');
        }
        if(file_exists($filePath) && !$overwrite){
            // error_log('VcfFile::save() - File \''.$filePath.'\' already exists');
            return false;
        }
        $result = file_put_contents($filePath, $this->__toString());
        return $result;
    }
    /**
     * Outputs a string all the formatted vCards in this VcfFile object
     * @return string 
     */
    public function __toString(): string
    {
        $content = '';
        foreach($this->_cards as $card){
            $content .= $card;
        }
        return $content;
    }
    /**
     * Countable interface implementation.
     * 
     * @return int<0, \max> 
     */
    public function count(): int{
        return count($this->_cards);
    }
    //
    // Array access implementation
    //
    /**
     * NON-STANDARD accessor.
     * This setter ignores the key (offset) and always appends the value to the underlying array.
     * 
     * The array is never associative other than a 0 based sequential index 
     * i.e., '$vCardCollection[/-?\d+/] = $vCard;' is the same as'$vCardCollection[] = $vCard;' 
     * @param mixed $offset Ignored.
     * @param mixed $value 
     * @return void 
     */
    public function offsetSet(mixed $offset, mixed $value): void {
        $this->_cards[] = $value;
    }
    public function offsetExists(mixed $offset): bool {
        return isset($this->_cards[$offset]);
    }
    public function offsetUnset(mixed $offset): void {
        unset($this->_cards[$offset]);
    }
    /**
     * NON-STANDARD array access.
     * The underlying array is never associative other than a sequential 0 based key index.
     * 
     * This key accessor only accepts integers representing a position in the 0 based sequence.
     * @param mixed $offset Only accepts an integer. Any other type will cause the accessor to return false.
     * @return mixed In effect will return VCCard|null|false, null if index is not set.
     */
    public function offsetGet(mixed $offset): mixed {
        if(!is_int($offset) || $offset < 0){
            return false;
        }
        return isset($this->_cards[$offset]) ? $this->_cards[$offset] : null;
    }
    //
    // Iterator implementation
    //
    public function current(): mixed{
        return $this->_cards[$this->_position];
    }
    public function key(): mixed{
        return $this->_position;
    }
    public function next(): void{
        ++$this->_position;
    }
    public function rewind(): void{
        $this->_position = 0;
    }
    public function valid(): bool{
        return isset($this->_cards[$this->_position]);
    }
    private array $_cards = [];
    private int $_position = 0;
}