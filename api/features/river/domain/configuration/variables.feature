Feature:
  In order to configure my tide
  As a user
  I want to be able to use and pass configuration variables

  Scenario: The variables are replaced in the configuration
    Given I have a flow with the following configuration:
    """
    variables:
        - name: FOO
          value: BAR
    """
    And I have a "continuous-pipe.yml" file in my repository that contains:
    """
    tasks:
        named:
            deploy:
                cluster: ${FOO}
                services: []
    """
    When a tide is created
    Then the configuration of the tide should contain at least:
    """
    tasks:
        named:
            deploy:
                cluster: BAR
    """

  Scenario: Variables with condition
    Given there is 1 application images in the repository
    And I have a "continuous-pipe.yml" file in my repository that contains:
    """
    variables:
        - name: CLUSTER
          value: bar
          condition: 'code_reference.branch == "production"'
        - name: CLUSTER
          value: foo
          condition: 'code_reference.branch == "master"'
        - name: CLUSTER
          value: baz
          condition: 'code_reference.branch == "feature/ABC"'

    tasks:
        deployment:
            deploy:
                cluster: ${CLUSTER}
    """
    When the configuration of the tide is generated for the branch "master"
    Then the generated configuration should contain at least:
    """
    tasks:
        deployment:
            deploy:
                cluster: foo
    """

  Scenario: Variable from an expression
    Given I have a "continuous-pipe.yml" file in my repository that contains:
    """
    variables:
        - name: BRANCH_NAME
          expression: code_reference.branch

    tasks:
        named:
            deploy:
                cluster: ${BRANCH_NAME}
                services: []
    """
    When the configuration of the tide is generated for the branch "master"
    Then the generated configuration should contain at least:
    """
    tasks:
        named:
            deploy:
                cluster: master
    """

  Scenario: We can define variables with the "deprecated" method
    Given I have a flow with the following configuration:
    """
    environment_variables:
        - name: FOO
          value: BAR
    """
    And I have a "continuous-pipe.yml" file in my repository that contains:
    """
    tasks:
        named:
            deploy:
                cluster: ${FOO}
                services: []
    """
    When a tide is created
    Then the configuration of the tide should contain at least:
    """
    tasks:
        named:
            deploy:
                cluster: BAR
    """

  Scenario: It overrides the variables only with the same conditions
    Given I have a flow with the following configuration:
    """
    environment_variables:
        - name: CLUSTER
          value: foo
    """
    And I have a "continuous-pipe.yml" file in my repository that contains:
    """
    environment_variables:
        - name: CLUSTER
          condition: code_reference.branch == 'master'
          value: bar
        - name: CLUSTER
          condition: code_reference.branch != 'master'
          value: baz

    tasks:
        named:
            deploy:
                cluster: ${CLUSTER}
                services: []
    """
    When a tide is created for the branch "master"
    Then the configuration of the tide should contain at least:
    """
    tasks:
        named:
            deploy:
                cluster: bar
    """

  Scenario: It allows empty variables
    Given I have a flow with the following configuration:
    """
    variables:
        - name: FOO
          value: ~
    """
    And I have a "continuous-pipe.yml" file in my repository that contains:
    """
    tasks:
        named:
            deploy:
                cluster: BAR${FOO}
                services: []
    """
    When a tide is created
    Then the configuration of the tide should contain at least:
    """
    tasks:
        named:
            deploy:
                cluster: BAR
    """

  Scenario: It converts key-value pairs to array of variables
    Given I have a flow with the following configuration:
    """
    variables:
        FOO: BAR
    """
    And I have a "docker-compose.yml" file in my repository that contains:
    """
    container:
        image: helloworld
    """
    And I have a "continuous-pipe.yml" file in my repository that contains:
    """
    tasks:
        named:
            deploy:
                cluster: ${FOO}
                services:
                    container:
                        specification:
                            environment_variables:
                                BAR: BAZ
    """
    When a tide is created
    Then the configuration of the tide should contain at least:
    """
    tasks:
        named:
            deploy:
                cluster: BAR
                services:
                    container:
                        specification:
                            environment_variables:
                                - name: BAR
                                  value: BAZ
    """

  Scenario: Not found variable will not be replaced
    Given I have a flow with the following configuration:
    """
    variables: []
    """
    And I have a "continuous-pipe.yml" file in my repository that contains:
    """
    tasks:
        named:
            deploy:
                cluster: ${FOO}
                services: []
    """
    When a tide is created
    Then the configuration of the tide should contain at least:
    """
    tasks:
        named:
            deploy:
                cluster: ${FOO}
    """

  Scenario: Uses the variable with an optional value
    Given I have a flow with the following configuration:
    """
    variables:
    - name: FOO
      value: bar
    """
    And I have a "continuous-pipe.yml" file in my repository that contains:
    """
    tasks:
        named:
            deploy:
                cluster: ${FOO?:my-cluster}
                services: []
    """
    When a tide is created
    Then the configuration of the tide should contain at least:
    """
    tasks:
        named:
            deploy:
                cluster: bar
    """

  Scenario: I can use an optional value if variable is not found
    Given I have a flow with the following configuration:
    """
    variables: []
    """
    And I have a "continuous-pipe.yml" file in my repository that contains:
    """
    tasks:
        named:
            deploy:
                cluster: ${FOO?:my-cluster}
                services: []
    """
    When a tide is created
    Then the configuration of the tide should contain at least:
    """
    tasks:
        named:
            deploy:
                cluster: my-cluster
    """
  Scenario: I can use an empty value if variable is not found
    Given I have a flow with the following configuration:
    """
    variables: []
    """
    And I have a "continuous-pipe.yml" file in my repository that contains:
    """
    tasks:
        named:
            deploy:
                cluster: my-cluster${UNSET_VAR?:}
                services: []
    """
    When a tide is created
    Then the configuration of the tide should contain at least:
    """
    tasks:
        named:
            deploy:
                cluster: my-cluster
                services: []
    """

  Scenario: It converts key-value pairs to array of variables even when using an expression
    Given I have a flow with the following configuration:
    """
    variables:
        SHA1:
            expression: code_reference.sha
    """
    And I have a "docker-compose.yml" file in my repository that contains:
    """
    container:
        image: helloworld
    """
    And I have a "continuous-pipe.yml" file in my repository that contains:
    """
    tasks:
        named:
            deploy:
                cluster: ${SHA1}
                services:
                    container: ~
    """
    When a tide is created for branch "master" and commit "1234abc"
    Then the configuration of the tide should contain at least:
    """
    tasks:
        named:
            deploy:
                cluster: 1234abc
    """
