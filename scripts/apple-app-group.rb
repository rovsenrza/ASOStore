#!/usr/bin/env ruby
# frozen_string_literal: true

# App Groups through the Apple Developer portal (fastlane Spaceship). The App Store Connect
# API cannot create an App Group or assign one to an App ID, so this is the one step that
# signs in with an Apple ID. Called by App\Services\Apple\AppGroupProvisioner.
#
#   apple-app-group.rb login                                      # interactive, once: password + 2FA
#   apple-app-group.rb ensure TEAM_ID GROUP_ID GROUP_NAME BUNDLE_ID
#
#   apple-app-group.rb spaceauth SESSION_FILE                     # interactive 2FA once, writes a 30-day session
#
# Credentials: FASTLANE_USER, and either FASTLANE_SESSION (a reusable ~30-day session captured by
# `spaceauth`, preferred) or FASTLANE_PASSWORD. With a session set, `login`/`ensure` never prompt
# for 2FA. Prints one JSON line: {"ok":true,...} or {"ok":false,"code":"...","message":"..."}.

require 'json'
require 'spaceship'

def answer(payload, status = 0)
  $stdout.puts(JSON.generate(payload))
  exit(status)
end

mode = ARGV[0]
user = ENV.fetch('FASTLANE_USER', '')
answer({ ok: false, code: 'NOT_CONFIGURED', message: 'FASTLANE_USER is not set' }, 2) if user.empty?

begin
  # Capture a reusable ~30-day session: this is the only step that needs a 2FA code typed in.
  if mode == 'spaceauth'
    session_file = ARGV[1]
    answer({ ok: false, code: 'USAGE', message: 'spaceauth SESSION_FILE' }, 2) if session_file.nil?
    runner = Spaceship::SpaceauthRunner.new(username: user).run
    File.write(session_file, runner.session_string)
    answer({ ok: true })
  end

  if mode == 'ensure'
    # A new 2FA code would be needed: never wait for input that nobody will type.
    $stdin.reopen(File::NULL)
  end
  # With FASTLANE_SESSION set, Spaceship reuses it (no password, no 2FA); otherwise it falls back
  # to the password, which only `login` with a terminal attached can answer a 2FA prompt for.
  password = ENV['FASTLANE_PASSWORD'].to_s.empty? ? nil : ENV['FASTLANE_PASSWORD']
  Spaceship::Portal.login(user, password)

  case mode
  when 'login'
    answer({ ok: true, teams: Spaceship::Portal.client.teams.map { |team| team['teamId'] } })
  when 'ensure'
    team_id, group_id, group_name, bundle_id = ARGV[1, 4]
    answer({ ok: false, code: 'USAGE', message: 'ensure TEAM_ID GROUP_ID GROUP_NAME BUNDLE_ID' }, 2) if [team_id, group_id, group_name, bundle_id].any?(&:nil?)

    Spaceship::Portal.client.team_id = team_id
    group = Spaceship::Portal.app_group.find(group_id) ||
            Spaceship::Portal.app_group.create!(group_id: group_id, name: group_name)
    app = Spaceship::Portal.app.find(bundle_id)
    answer({ ok: false, code: 'APP_ID_NOT_FOUND', message: "App ID #{bundle_id} not found" }, 1) if app.nil?

    app = app.update_service(Spaceship::Portal.app_service.app_group.on)
    app.associate_groups([group])
    answer({ ok: true, group: group.group_id, bundle_id: bundle_id })
  else
    answer({ ok: false, code: 'USAGE', message: 'login | ensure TEAM_ID GROUP_ID GROUP_NAME BUNDLE_ID' }, 2)
  end
rescue Spaceship::Client::InvalidUserCredentialsError, Spaceship::Client::UnauthorizedAccessError,
       Spaceship::AccessForbiddenError, Spaceship::Client::NoUserCredentialsError, EOFError, IOError => e
  answer({ ok: false, code: 'SESSION_EXPIRED', message: "#{e.class}: #{e.message}"[0, 500] }, 3)
rescue StandardError => e
  answer({ ok: false, code: 'PORTAL_ERROR', message: "#{e.class}: #{e.message}"[0, 500] }, 1)
end
