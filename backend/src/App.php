<?php
declare(strict_types=1);

namespace Terboekt;

final class App
{
    private ?Repositories\BookingRepository $bookings = null;
    private ?Repositories\AvailabilityBlockRepository $blocks = null;
    private ?Repositories\RateRuleRepository $rates = null;
    private ?Repositories\CalendarConnectionRepository $calendars = null;
    private ?Repositories\PropertySettingsRepository $settings = null;
    private ?Repositories\EmailLogRepository $emailLogs = null;
    private ?Repositories\AdminUserRepository $admins = null;
    private ?Repositories\BookingStatusHistoryRepository $history = null;
    private ?Repositories\OccupancyNightRepository $occupancy = null;
    private ?Repositories\BookingAuditRepository $audit = null;
    private ?Services\BookingStatusService $status = null;
    private ?Services\AvailabilityService $availability = null;
    private ?Services\PricingService $pricing = null;
    private ?Services\CalendarSyncService $calendarSync = null;
    private ?Services\IcalExportService $icalExport = null;
    private ?Services\EmailService $email = null;
    private ?Services\AuthService $auth = null;
    private ?Services\PublishedRates $publishedRates = null;

    public function __construct(
        public readonly Config $config,
        public readonly Database $db,
    ) {
    }

    public static function boot(?Config $config = null): self
    {
        if ($config === null) {
            $config = require TERBOEKT_BACKEND . '/bootstrap.php';
        }
        return new self($config, new Database($config));
    }

    public function bookings(): Repositories\BookingRepository
    {
        return $this->bookings ??= new Repositories\BookingRepository($this->db);
    }

    public function blocks(): Repositories\AvailabilityBlockRepository
    {
        return $this->blocks ??= new Repositories\AvailabilityBlockRepository($this->db);
    }

    public function rates(): Repositories\RateRuleRepository
    {
        return $this->rates ??= new Repositories\RateRuleRepository($this->db);
    }

    public function calendars(): Repositories\CalendarConnectionRepository
    {
        return $this->calendars ??= new Repositories\CalendarConnectionRepository($this->db);
    }

    public function settings(): Repositories\PropertySettingsRepository
    {
        return $this->settings ??= new Repositories\PropertySettingsRepository($this->db);
    }

    public function emailLogs(): Repositories\EmailLogRepository
    {
        return $this->emailLogs ??= new Repositories\EmailLogRepository($this->db);
    }

    public function admins(): Repositories\AdminUserRepository
    {
        return $this->admins ??= new Repositories\AdminUserRepository($this->db);
    }

    public function history(): Repositories\BookingStatusHistoryRepository
    {
        return $this->history ??= new Repositories\BookingStatusHistoryRepository($this->db);
    }

    public function occupancy(): Repositories\OccupancyNightRepository
    {
        return $this->occupancy ??= new Repositories\OccupancyNightRepository($this->db);
    }

    public function audit(): Repositories\BookingAuditRepository
    {
        return $this->audit ??= new Repositories\BookingAuditRepository($this->db);
    }

    public function status(): Services\BookingStatusService
    {
        return $this->status ??= new Services\BookingStatusService(
            $this->db,
            $this->bookings(),
            $this->history(),
            $this->blocks(),
            $this->settings(),
            $this->occupancy(),
            $this->audit(),
        );
    }

    public function publishedRates(): Services\PublishedRates
    {
        return $this->publishedRates ??= Services\PublishedRates::load($this->config->ratesJsonPath());
    }

    public function pricing(): Services\PricingService
    {
        return $this->pricing ??= new Services\PricingService($this->rates(), $this->settings(), $this->publishedRates());
    }

    public function availability(): Services\AvailabilityService
    {
        return $this->availability ??= new Services\AvailabilityService(
            $this->db,
            $this->bookings(),
            $this->blocks(),
            $this->calendars(),
            $this->occupancy(),
            $this->settings(),
        );
    }

    public function calendarSync(): Services\CalendarSyncService
    {
        return $this->calendarSync ??= new Services\CalendarSyncService(
            $this->db,
            $this->calendars(),
            $this->blocks(),
            new Services\IcalParser(),
            new Http\CurlHttpClient(),
            $this->config,
        );
    }

    public function icalExport(): Services\IcalExportService
    {
        return $this->icalExport ??= new Services\IcalExportService(
            $this->bookings(),
            $this->blocks(),
            $this->settings(),
            $this->config->icalExportSecret,
        );
    }

    public function email(): Services\EmailService
    {
        return $this->email ??= new Services\EmailService(
            $this->config,
            $this->emailLogs(),
            $this->settings(),
            new Mail\SmtpTransport($this->config),
        );
    }

    public function expiry(): Services\BookingExpiryService
    {
        return new Services\BookingExpiryService($this);
    }

    public function workflow(): Services\BookingWorkflowService
    {
        return new Services\BookingWorkflowService($this);
    }

    public function auth(): Services\AuthService
    {
        return $this->auth ??= new Services\AuthService($this->db, $this->admins(), $this->config);
    }
}
