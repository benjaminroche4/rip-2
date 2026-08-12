<?php

declare(strict_types=1);

namespace App\PropertyListing\Service;

use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Shared submission folder name: address + owner name slugged to
 * "12ruederivoli75001parismariedupont-20260720". The date suffix keeps
 * same-owner/same-address re-submissions apart across days.
 */
trait ListingFolderNaming
{
    abstract protected function slugger(): SluggerInterface;

    public function folderName(string $address, string $fullName, \DateTimeImmutable $date): string
    {
        $slug = strtolower($this->slugger()->slug($address.$fullName)->toString());
        $slug = preg_replace('/[^a-z0-9]/', '', $slug) ?? '';

        return ('' !== $slug ? $slug : 'unknown').'-'.$date->format('Ymd');
    }
}
