@mod @mod_peering
Feature: peering should remember collapsed/expanded sections in view page.
  In order to keep the last state of collapsed/expanded sections in view page
  As an user
  I need to be able to choose collapsed/expanded, and after refresh the page it will display collapsed/expanded I chose before.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
      | student1 | Student   | 1        | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course1  | c1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | c1     | editingteacher |
      | student1 | c1     | student        |
    And the following "activities" exist:
      | activity | name       | course | idnumber  |
      | peering | peering 1 | c1     | peering1 |

  @javascript
  Scenario: Check section in view page can be remembered.
    Given I am on the "peering 1" "peering activity" page logged in as teacher1
    When I change phase in peering "peering 1" to "Submission phase"
    And I wait until the page is ready

    And I am on the "peering 1" "peering activity" page logged in as student1
    Then I should see "You have not submitted your work yet"
    And I click on "Your submission" "link"
    And I should not see "You have not submitted your work yet"
    And I reload the page
    And I wait until the page is ready
    And I should not see "You have not submitted your work yet"
