<?php
include "../vcf.php";
include "test.php";

try{
    echo '<h1>Tests for JF\'s vCard v4.0 processor</h1><hr>';
    //
    // VCProperty
    //
    echo '<h3>VCProperty testing</h3>';
    $contentLine = "ADR;GEO=\"geo:12.3457,78.910\";LABEL=\"Mr. John Q. Public; Esq.\\nMail Drop: TNE QB\\n123 Main Street\\nAny Town, CA  91921-1234 U.S.A.\";TYPE=HOME;TYPE=POSTAL;TYPE=pref:;;123 Main Street;Any Town;CA;91921-1234;U.S.A.";
    $vprop = new VCProperty($contentLine);
    assertTrue('VCProperty creation from property parsing', !!$vprop);
    assertMatch('__toString() matches ADR content line', 
                "ADR;geo=\"geo:12.3457,78.910\";label=\"Mr. John Q. Public; Esq.\\nMail Drop: TNE QB\\n123 Main Street\\nAny Town, CA  91921-1234 U.S.A.\";type=HOME;type=POSTAL;type=pref:;;123 Main Street;Any Town;CA;91921-1234;U.S.A.",
                unfold($vprop));
    assertTrue('VCProperty::pref() returns 1 if no pref parameter but a type=pref parameter exists', $vprop->pref() === 1);
    assertMatch('Match name', 'ADR', $vprop->name);
    assertMatch('Match value',';;123 Main Street;Any Town;CA;91921-1234;U.S.A.', $vprop->value);
    assertTrue('Has type value "HOME,POSTAL"', $vprop->hasTypeValue('HOME,POSTAL')); 
    assertMatch("Match geo parameter value", "\"geo:12.3457,78.910\"", $vprop->getParameterValue('geo'));
    assertMatch("Match label parameter value", "\"Mr. John Q. Public; Esq.\\nMail Drop: TNE QB\\n123 Main Street\\nAny Town, CA  91921-1234 U.S.A.\"", $vprop->getParameterValue('label'));
    $newprop = VCProperty::create('FN', 'Jack Sparrow');
    assertTrue('VCProperty::create() test', !!$newprop);
    assertMatch('Match name', 'FN', $newprop->name);
    assertMatch('Match value', 'Jack Sparrow', $newprop->value);
    assertFalse('Can\'t set TEL type param to property othern than TEL', $newprop->setParameter('type', 'voice'));
    assertFalse('Can\'t set invalid parameter', $newprop->setParameter('crapparam', 'voice'));
    $excpetionThrown = false;
    try{
        $bad = VCProperty::create('HALLUCINATING', 'MONKEY');
    }catch(BadMethodCallException $bmc){
        $excpetionThrown = true;
    }catch(Exception $e){
        echoTestResult('exceptionTest', 'Unexpected Exception thrown while testing creation using unrecognized property name', false, $e->getMessage());
    }
    echoTestResult('exceptionTest', 'Creating VCProperty throws BadMethodCallException when using unrecognized property name', $excpetionThrown);
    assertFalse('"type" parameter value "pref" is not valid in v4.0', $newprop->setParameter('type', 'pref'));
    assertTrue('"home" is vaid value for param "type"', $newprop->setParameter('type','home'));
    assertTrue('Set more than one "type" parameter', $newprop->setParameter('type','x-wild'));
    assertMatch('Match type parameter values to "home,x-wild"', 'home,x-wild', $newprop->getParameterValue('type'));
    assertFalse('getParameterValue() returns false when parameter not found', $newprop->getParameterValue('pref'));
    //
    // VCAddress
    //
    echo '<hr><h3>VCAddress testing</h3>';
    $vprop2 = new VCAddress($vprop);
    assertTrue('VCAddress from VCProperty Creation', !!$vprop2);
    assertMatch('Match po_box', '', $vprop2->po_box);
    assertMatch('Match extended', '', $vprop2->extended);
    assertMatch('Match street', '123 Main Street', $vprop2->street);
    assertMatch('Match city', 'Any Town', $vprop2->city);
    assertMatch('Match region','CA', $vprop2->region);
    assertMatch('Match zip', '91921-1234', $vprop2->zip);
    assertMatch('Match country', 'U.S.A.', $vprop2->country);
    $address = VCAddress::createADR('111 my street', 'My City', 'My Region', 'G1Q 1Q9', 'My Country');
    assertTrue('VCAddress::createADR()', !!$address);
    $excpetionThrown = false;
    try{
        $bad = new VCAddress(new VCProperty("N:Jean;Béliveau;;;"));
    }
    catch(UnexpectedValueException $uve){
        $excpetionThrown = true;
    }catch(Exception $e){
        echoTestResult('exceptionTest', 'Unexpected Exception thrown while testing VCAddress creation using VCProperty for a different vCard property', false, $e->getMessage());
    }
    echoTestResult('exceptionTest', 'VCAddress::__construct() throws UnexpectedValueException when using VCProperty for a different vCard property', $excpetionThrown);


    //
    // VCN
    //
    echo '<hr><h3>VCN testing</h3>';
    $nameProp = new VCN("n:Stevenson;John;Philip,Paul;Dr.;Jr.,M.D.,A.C.P.");
    assertTrue('VCN from parsing', !!$nameProp);
    assertFalse('Setting "type" parameter to property "N" fails', $nameProp->setParameter('type', 'pref'));
    $nameProp2 = VCN::createN('davignon','Jean-François', 'Lambert','Mr.');
    assertTrue('VCN from VCN::createN()', !!$nameProp2);
    $nameProp3 = new VCN(new VCProperty("N:Jean;Béliveau;;;"));
    assertTrue('VCN from VCProperty', !!$nameProp3);
    $excpetionThrown = false;
    try{
        $bad = new VCN($vprop2);
    }
    catch(UnexpectedValueException $uve){
        $excpetionThrown = true;
    }catch(Exception $e){
        echoTestResult('exceptionTest', 'Unexpected Exception thrown while testing creation VCN using VCProperty for a different vCard property', false, $e->getMessage());
    }
    echoTestResult('exceptionTest', 'VCN::__construct() throws UnexpectedValueException when using VCProperty for a different vCard property', $excpetionThrown);

    //
    // VCCard
    //
    echo '<hr><h3>VCCard testing</h3>';
    $card = new VCCard();
    assertTrue('Creation of VCCard', !!$card);
    assertNull('fn() returns null if the "FN" property was not already added', $card->fn());
    $card[] = new VCProperty('FN:Jean-François Davignon');
    assertMatch('Assert fn() returns "Jean-François Davignon"', "Jean-François Davignon", $card->fn());
    $vcfnprop = VCN::createN('Davignon', 'Jean-François');
    $card[] = $vcfnprop;
    $vcfn = $card->property('N');
    assertMatch('VCCard::property("N") returned a VCN object', 'VCN', get_class($vcfn));
    $card[] = $vprop2;
    $vcfadr = $card->property('adr');
    assertMatch('VCCard::property("adr") returned a VCAddress object', 'VCAddress', get_class($vcfadr));
    $vcffn = $card->property('fn');
    assertMatch('VCCard::property("fn") returned a VCProperty object', 'VCProperty', get_class($vcffn));
    $cardstring = "BEGIN:VCARD\r\nVERSION:4.0\r\nFN:Jean-François Davignon\r\nN:Davignon;Jean-François;;;\r\nADR;geo=\"geo:12.3457,78.910\";label=\"Mr. John Q. Public; Esq.\\nMail Drop: TN\r\n E QB\\n123 Main Street\\nAny Town, CA  91921-1234 U.S.A.\";type=HOME;type=POST\r\n AL;type=pref:;;123 Main Street;Any Town;CA;91921-1234;U.S.A.\r\nEND:VCARD\r\n";
    assertMatch( 'VCCard::__toString() returns vCard', $cardstring, $card->__toString());
    $card = new VCCard($cardstring);
    assertTrue('Creation of VCCard from string', !!$card);
    $vcfn = $card->property('N');
    assertMatch('VCCard::property("N") returned a VCN object', 'VCN', get_class($vcfn));
    $vcfadr = $card->property('adr');
    assertMatch('VCCard::property("adr") returned a VCAddress object', 'VCAddress', get_class($vcfadr));
    $vcffn = $card->property('fn');
    assertMatch('VCCard::property("fn") returned a VCProperty object', 'VCProperty', get_class($vcffn));

    //
    // VCCardCollection
    //
    echo '<hr><h3>VCCardCollection testing</h3>';
    $vCardCollection = VCCardCollection::createFromFile('assets/cards.vcf');
    assertTrue('VCCardCollection created from loading file', !!$vCardCollection);
    assertMatch('First vCard is Peter Hondo', 'Peter Hondo - PRODID, N, FN, ORG, TEL', $vCardCollection[0]->fn().' - '.implode(", ",$vCardCollection[0]->propertyNames()));
    assertMatch('Second vCard is Isabelle Birder', 'Isabelle Birder - PRODID, N, FN, EMAIL, TEL, ADR, PHOTO', $vCardCollection[1]->fn().' - '.implode(", ",$vCardCollection[1]->propertyNames()));
    assertTrue('Isabelle Birder photo type is JPEG', $vCardCollection[1]->property('PHOTO')->hasTypeValue('JPEG'));
    assertMatch('Photo encoding is b', 'b', $vCardCollection[1]->property('PHOTO')->getParameterValue('encoding'));
    $vCards2 = new VCCardCollection();
    assertTrue('VCCardCollection default construction', !!$vCards2);
    $vCard = new VCCard();
    assertTrue('VCCard default construction', !!$vCard);
    $vCard[] = VCProperty::create('FN','Jean-Luc Picard');
    $vcn = VCN::createN('Picard', 'Jean-Luc', 'Sanregret', 'Admiral', 'Starfleet');
    assertTrue('VCN::createN()', !!$vcn);
    $vcn->setParameter('SORT-AS', '"Picard, Jean-Luc"');
    $vCard[] = $vcn;
    $vCards2[] = $vCard;
    // echo_log('Card:<br>'.$vCard);
    // echo_log('Collection:<br>'.$vCards2);
    assertMatch("Collection string matches card string", $vCard, $vCards2);
}catch(Exception $e){
    echoTestResult('globalCatch', 'Unhandled Exception:', false, $e->getMessage());
}finally{
    echo '<br><hr><span style="font-style: italic;">Ran what could be run.</span><br>';
}
function echo_log(mixed $value): void{
    if(!is_string($value)){
        echo '<pre style="font-family: monospace; font-size: 14px;">'.json_encode($value, JSON_PRETTY_PRINT).'</pre>';
        return;
    }
    echo '<pre style="font-family: monospace; font-size: 14px;">'.$value.'</pre>';
}

function unfold(string $text): string{
    return preg_replace("/(?:(?:\r\n)|\n) /", "", $text);
}