pipeline {
    agent any

    environment {
        HARBOR_HOST = '192.168.137.5'
        HARBOR_REPO = 'mycode/devops-glue'
        PHP_API     = 'http://192.168.137.5:8080'
    }

    stages {
        stage('Build & Push') {
            steps {
                script {
                    def image = "${HARBOR_HOST}/${HARBOR_REPO}"
                    def tag = "${env.BUILD_NUMBER}-${env.GIT_COMMIT?.take(7) ?: ''}"

                    sh "docker login ${HARBOR_HOST} -u ${HARBOR_USER} -p ${HARBOR_PASSWORD}"
                    sh "docker build -t ${image}:${tag} ."
                    sh "docker push ${image}:${tag}"
                    sh "docker tag ${image}:${tag} ${image}:latest"
                    sh "docker push ${image}:latest"

                    env.IMAGE_TAG = tag
                    echo "推送完成: ${image}:${tag}"
                }
            }
        }

        stage('Scan-Sync') {
            steps {
                script {
                    sh """
                        curl -s -X POST '${PHP_API}/api/build/${env.JOB_NAME}/scan-sync' \
                          -H 'Content-Type: application/json' \
                          -d '{"tag":"${env.IMAGE_TAG}"}'
                    """
                    echo "scan-sync 完成: ${env.JOB_NAME} tag=${env.IMAGE_TAG}"
                }
            }
        }
    }
}
