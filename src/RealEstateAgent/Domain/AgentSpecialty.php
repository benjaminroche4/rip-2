<?php

declare(strict_types=1);

namespace App\RealEstateAgent\Domain;

/**
 * What kind of deals a directory agent handles. An agent can do both, so
 * the entity stores a list of specialties.
 */
enum AgentSpecialty: string
{
    case Location = 'location';
    case Transaction = 'transaction';

    public function labelKey(): string
    {
        return 'admin.agents.specialty.'.$this->value;
    }
}
