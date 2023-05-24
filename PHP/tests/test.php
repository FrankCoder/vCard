<?php
//
// Trivial test functions that echo results back to browser
//
/**
 * Prints Passed or Failed followed by test name.
 * @param string $calledBy 
 * @param string $testName 
 * @param bool $testResult 
 * @param string $errorMessage optional message to give more details about error.
 * @return void 
 */
function echoTestResult(string $calledBy, string $testName, bool $testResult, string $errorMessage = ''): void {
    if(!$testResult){
        echo '<span style="font-family: monospace; font-size: 14px;">'.$calledBy.'<span style="color: red; font-weight: bold;">&nbsp;Failed</span>&nbsp;'.$testName.'</span><br>';
        if($errorMessage !== ''){
            echo '<span style="color: red; font-family: monospace; font-size: 14px;"><pre>'.$errorMessage.'</pre></span><br>';
        }
    }
    else{
        echo '<span style="font-family: monospace; font-size: 14px;">'.$calledBy.'<span style="color: green; font-weight: bold;">&nbsp;Passed</span>&nbsp;'.$testName.'</span><br>';
    }
}
/**
 * Tests whether $testResult is true
 * @param string $testName 
 * @param bool $result 
 * @return bool 
 */
function assertTrue(string $testName, bool $result): bool{
    echoTestResult('assertTrue&nbsp;&nbsp;&nbsp;', $testName, $result);
    return $result;
}
/**
 * Tests whether $testResult is false
 * @param string $testName 
 * @param bool $result 
 * @return bool 
 */
function assertFalse(string $testName, bool $result): bool{
    echoTestResult('assertFalse&nbsp;&nbsp;' ,$testName, !$result);
    return !$result;
}
/**
 * Tests whether two string match
 * @param string $testName 
 * @param string $expected 
 * @param string $actual 
 * @return bool 
 */
function assertMatch(string $testName, string $expected, string $actual): bool{
    $test = $expected === $actual;
    echoTestResult('assertMatch&nbsp;&nbsp;', $testName, $test, 'Expected: '.$expected.'<br><br>Got: '.$actual);
    return $test;
}
/**
 * Asserts that $value is null
 * @param string $testName 
 * @param mixed $value 
 * @return bool 
 */
function assertNull(string $testName, mixed $value): bool{
    $test = $value === null;
    echoTestResult('assertNull&nbsp;&nbsp;&nbsp;', $testName, $test);
    return $test;
}