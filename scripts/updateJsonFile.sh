#!/bin/bash

# Update the specified JSON file.
# The "--local" and "--remote" arguments just tell us that we're updating
# the local or remote Website config file so we can say that in output messages.

# Allow this script to be executed manually, which requires several variables to be set.
[[ -z ${ALLSKY_HOME} ]] && export ALLSKY_HOME="$( realpath "$( dirname "${BASH_ARGV0}" )/.." )"
ME="$( basename "${BASH_ARGV0}" )"

#shellcheck disable=SC1091 source-path=.
source "${ALLSKY_HOME}/variables.sh"		|| exit "${EXIT_ERROR_STOP}"
#shellcheck source-path=scripts
source "${ALLSKY_SCRIPTS}/functions.sh"		|| exit "${EXIT_ERROR_STOP}"

function usage_and_exit()
{
	local RET=${1}
	exec >&2

	local MSG="\nUsage: ${ME} [--help] [--debug] [--verbosity silent|summary|verbose]"
	MSG+="\n   --local | --remote | --file file"
	MSG+="\n   key  label  new_value  [...]"
	if [[ ${RET} -eq 0 ]]; then
		w_ "${MSG}"
	else
		e_ "${MSG}"
	fi
	echo "There must be a multiple of 3 arguments."
	exit "${RET}"
}

# Check arguments
OK="true"
HELP="false"
DEBUG="false"
VERBOSITY="summary"
FILE=""
WEBSITE_TYPE=""
while [[ $# -gt 0 ]]; do
	ARG="${1}"
	case "${ARG,,}" in
		--help)
			HELP="true"
			;;
		--debug)
			DEBUG="true"
			;;
		--verbosity)
			VERBOSITY="${2}"
			shift
			;;
		--local)
			FILE="${ALLSKY_WEBSITE_CONFIGURATION_FILE}"
			WEBSITE_TYPE="Local"
			;;
		--remote)
			FILE="${ALLSKY_REMOTE_WEBSITE_CONFIGURATION_FILE}"
			WEBSITE_TYPE="Remote"
			;;
		--file)
			FILE="${2}"
			[[ -z ${FILE} ]] && OK="false"
			shift
			;;
		-*)
			e_ "ERROR: Unknown argument: '${ARG}'" >&2
			OK="false"
			;;
		*)
			break
			;;
	esac
	shift
done

[[ ${HELP} == "true" ]] && usage_and_exit 0
[[ ${OK} == "false" ]] && usage_and_exit 1
[[ $# -eq 0 || -z ${FILE} ]] && usage_and_exit 1
[[ $(($# % 3)) -ne 0 ]] && usage_and_exit 2

if [[ ! -f ${FILE} ]]; then
	e_ "ERROR: Configuration file not found: '${FILE}'." >&2
	exit 1
fi

#shellcheck disable=SC2191
JQ_STRING=(.comment = .comment)
OUTPUT_MESSAGE=""
NUMRE="^[+-]?[0-9]+([.][0-9]+)?$"

while [[ $# -gt 0 ]]; do
	FIELD="${1}"
	LABEL="${2}"
	NEW_VALUE="${3}"

	# Convert HTML code for apostrophy back to character.
	apos="&#x27"
	NEW_VALUE="${NEW_VALUE/${apos}/\'}"
	NEW="${NEW_VALUE}"
	NEW_VALUE="${NEW_VALUE//\"/\\\"}"	# Handle double quotes

	[[ ${DEBUG} == "true" ]] && d_ "Update '${LABEL}' to [${NEW_VALUE}]."

	# Only put quotes around ${NEW_VALUE} if it's a string,
	# i.e., not a number or a special name.
	if  [[ ! (${NEW_VALUE} =~ ${NUMRE}) && ${NEW_VALUE} != "true" && ${NEW_VALUE} != "false" &&
			${NEW_VALUE} != "null" && ${NEW_VALUE} != "--delete" ]]; then
		Q='"'
		NEW_VALUE="${Q}${NEW_VALUE}${Q}"
	fi
	if [[ ${NEW_VALUE} == "--delete" ]]; then
		JQ_STRING+=( "| del(${FIELD})" )
		OUTPUT_MESSAGE+="'${LABEL}' deleted."
	else
		JQ_STRING+=( "| .${FIELD} = ${NEW_VALUE}" )
		OUTPUT_MESSAGE+="'${LABEL}' updated to ${wBOLD}${NEW}${wNBOLD}."
	fi

	shift 3

	[ $# -gt 0 ] && OUTPUT_MESSAGE+="${wBR}"
done


# shellcheck disable=SC2124
S="${JQ_STRING[@]}"

[[ ${DEBUG} == "true" ]] && d_ "Executing:   jq '${S}' ${FILE}"

# Need to use "jq", not "settings".
if OUTPUT="$( jq "${S}" "${FILE}" 2>&1 > /tmp/x && mv /tmp/x "${FILE}" )"; then
	if [[ ${VERBOSITY} == "verbose" ]]; then
		o_ "${OUTPUT_MESSAGE}"
	elif [[ ${VERBOSITY} == "summary" ]]; then
		if [[ -n ${WEBSITE_TYPE} ]]; then
			o_ "${WEBSITE_TYPE} Allsky Website ${ALLSKY_WEBSITE_CONFIGURATION_NAME} UPDATED"
		else
			o_ "'${FILE}' UPDATED"
		fi
	fi		# nothing if "silent"
	exit 0
else
	{
		e_ "ERROR: unable to update data in '${FILE}':"
		echo "   ${OUTPUT}"
	} >&2
	exit 1
fi
