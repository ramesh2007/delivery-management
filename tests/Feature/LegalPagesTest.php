<?php

namespace Tests\Feature;

use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    public function test_privacy_policy_page_renders_successfully()
    {
        $response = $this->get('/privacy-policy');
        $response->assertStatus(200);
        $response->assertSee('Privacy Policy for Halamama WMS');
        $response->assertSee('Information We Collect');
        $response->assertSee('Camera Access');
        $response->assertSee('support@halamama.com');

        $aliasResponse = $this->get('/privacy');
        $aliasResponse->assertStatus(200);
    }

    public function test_terms_and_conditions_page_renders_successfully()
    {
        $response = $this->get('/terms-conditions');
        $response->assertStatus(200);
        $response->assertSee('Terms and Conditions for Halamama WMS');
        $response->assertSee('Authorized Use and License');
        $response->assertSee('Camera & Storage Permissions');
        $response->assertSee('support@halamama.com');

        $conditionResponse = $this->get('/condition');
        $conditionResponse->assertStatus(200);

        $termsConditionResponse = $this->get('/terms-condition');
        $termsConditionResponse->assertStatus(200);
    }
}
