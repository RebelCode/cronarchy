[![Build Status](https://travis-ci.org/RebelCode/cronarchy.svg?branch=master)](https://travis-ci.org/RebelCode/cronarchy)
[![Code Climate](https://codeclimate.com/github/RebelCode/cronarchy/badges/gpa.svg)](https://codeclimate.com/github/RebelCode/cronarchy)
[![Test Coverage](https://codeclimate.com/github/RebelCode/cronarchy/badges/coverage.svg)](https://codeclimate.com/github/RebelCode/cronarchy/coverage)
[![Latest Stable Version](https://poser.pugx.org/rebelcode/cronarchy/version)](https://packagist.org/packages/rebelcode/cronarchy)

# Cronarchy

Welcome! This is cron anarchy!

# Introduction

Cronarchy is a pseudo-cron system similar to WP Cron. Unlike WP Cron however, Cronarchy exposes a more structured API and is developed for reliability, performance and consistent invocation.

We built Cronarchy in a way such that it can be distributed with plugins and themes without interfering with WP Cron and any plugins or themes that depend on it. This is in stark contrast with other solutions, such as [Cavalcade], which are intended to be used as drop-in replacements for the entire WP cron system. If you wish to give your entire WordPress site a cron upgrade, we recommend those solutions instead.

# Why use Cronarchy over WP Cron?

For us, WP Cron is an unreliable system that resulted in excess customer support. Often times what seemed like bugs in our plugins turned out to be a problem with WP Cron, be it a job not running, unexpectedly terminating or an errenous cron job holding back the rest of the job queue.

So we built Cronarchy. Here are some of the reasons why we think you should give a try:

* Object-oriented API
* DI-friendly
* Auto detects when it's stuck and resets
* Detects unexpected script failure, cancellation or abortion
* Better performance through the use of a dedicated jobs table
* Can be triggered programmatically

# Requirements
* PHP >= 5.4.0
* WordPress >= 4.7

# Installation

Check out the **[Installation][wiki-install]** wiki page for instructions on how to install and set up Cronarchy in your plugin or theme.

# Links

* [Wiki]
* [Cavalcade] by [Human Made][humanmade]

[cavalcade]: https://github.com/humanmade/cavalcade
[humanmade]: https://github.com/humanmade/cavalcade
[wiki]: https://github.com/RebelCode/cronarchy/wiki
[wiki-install]: https://github.com/RebelCode/cronarchy/wiki/Installation
