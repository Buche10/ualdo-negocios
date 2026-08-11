<?php

namespace App\Skills;

use App\Models\Business;
use App\Models\Contact;

interface SkillProvider
{
    public function vertical(): string;

    /**
     * @return array<int, mixed>
     */
    public function getTools(?Contact $contact = null): array;

    public function promptSection(?Business $business, Contact $contact, string $tz): string;
}
