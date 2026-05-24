const INITIAL_STATE = Object.freeze( {
	status: 'idle',
	copyStatus: 'idle',
	popupStatus: 'idle',
	authSessionId: '',
	verificationUrl: '',
	userCode: '',
	error: '',
	connection: null,
	catalog: null,
	providerStatus: null,
} );

const FIRST_INTERVAL_MS = 2000;
const SECOND_INTERVAL_MS = 5000;
const FAST_POLL_WINDOW_MS = 30000;
const TIMEOUT_MS = 10 * 60 * 1000;

function toErrorMessage( error, fallback ) {
	if ( error && typeof error.message === 'string' && error.message ) {
		return error.message;
	}

	return fallback;
}

export function getCodexConnectionPendingSupportText( state, labels = {} ) {
	if ( state?.error ) {
		return state.error;
	}

	if ( state?.copyStatus === 'failed' ) {
		return labels.copyFailed || '';
	}

	if ( state?.popupStatus === 'blocked' ) {
		return labels.popupBlocked || '';
	}

	if ( state?.copyStatus === 'copied' ) {
		return labels.copied || '';
	}

	return labels.defaultText || '';
}

async function readJsonResponse( response ) {
	const body = await response.json();

	if ( ! response.ok ) {
		const message = body?.error?.message || body?.message || `Request failed with HTTP ${ response.status }`;
		throw new Error( message );
	}

	return body;
}

function createPrearmedClipboardCopy( windowObject, clipboard ) {
	const ClipboardItemConstructor = windowObject.ClipboardItem;
	const BlobConstructor = windowObject.Blob;

	if ( ! clipboard?.write || ! ClipboardItemConstructor || ! BlobConstructor ) {
		return null;
	}

	let resolveText;
	let rejectText;
	const dataPromise = new Promise( ( resolve, reject ) => {
		resolveText = resolve;
		rejectText = reject;
	} );
	const blobPromise = dataPromise.then(
		( text ) => new BlobConstructor( [ text ], { type: 'text/plain' } )
	);
	blobPromise.catch( () => {} );

	try {
		const item = new ClipboardItemConstructor( {
			'text/plain': blobPromise,
		} );
		const writePromise = Promise.resolve( clipboard.write( [ item ] ) );
		writePromise.catch( () => {} );

		return {
			copy( value ) {
				resolveText( String( value ) );
				return writePromise;
			},
			cancel() {
				rejectText( new Error( 'Clipboard copy was canceled.' ) );
			},
		};
	} catch ( error ) {
		rejectText( error );
		return null;
	}
}

export function createCodexConnectionFlow( options ) {
	const windowObject = options.windowObject || window;
	const documentObject = windowObject.document;
	const fetchObject = options.fetch || windowObject.fetch?.bind( windowObject );
	const clipboard = options.clipboard || windowObject.navigator?.clipboard;
	const now = options.now || Date.now;
	const setTimer = options.setTimeout || windowObject.setTimeout.bind( windowObject );
	const clearTimer = options.clearTimeout || windowObject.clearTimeout.bind( windowObject );
	const onStateChange = options.onStateChange || ( () => {} );
	const onError = options.onError || ( () => {} );
	let state = { ...INITIAL_STATE };
	let startedAt = 0;
	let timer = null;
	let polling = false;
	let pollAgain = false;
	let activePoll = null;
	let stopped = false;
	let visibilityListener = null;

	const request = async ( url, init = {} ) => {
		if ( ! fetchObject ) {
			throw new Error( 'The browser does not provide fetch().' );
		}

		const headers = {
			Accept: 'application/json',
			...( init.headers || {} ),
		};

		if ( options.restNonce ) {
			headers[ 'X-WP-Nonce' ] = options.restNonce;
		}

		return readJsonResponse(
			await fetchObject( url, {
				credentials: 'same-origin',
				...init,
				headers,
			} )
		);
	};

	const emit = ( patch ) => {
		state = { ...state, ...patch };
		onStateChange( state );

		if ( documentObject?.dispatchEvent && windowObject.CustomEvent ) {
			documentObject.dispatchEvent(
				new windowObject.CustomEvent( 'codex-provider-connection-state', {
					detail: state,
				} )
			);
		}

		return state;
	};

	const clearScheduledPoll = () => {
		if ( null !== timer ) {
			clearTimer( timer );
			timer = null;
		}
	};

	const elapsed = () => Math.max( 0, now() - startedAt );

	function removeResumeListeners() {
		windowObject.removeEventListener?.( 'focus', resume );
		windowObject.removeEventListener?.( 'pageshow', resume );

		if ( visibilityListener ) {
			documentObject?.removeEventListener?.( 'visibilitychange', visibilityListener );
			visibilityListener = null;
		}
	}

	const stop = () => {
		stopped = true;
		clearScheduledPoll();
		removeResumeListeners();
	};

	const terminal = ( patch ) => {
		clearScheduledPoll();
		removeResumeListeners();
		return emit( patch );
	};

	const scheduleNextPoll = () => {
		clearScheduledPoll();

		if ( stopped || state.status !== 'pending' ) {
			return;
		}

		if ( elapsed() >= TIMEOUT_MS ) {
			terminal( { status: 'timed_out' } );
			return;
		}

		const interval = elapsed() < FAST_POLL_WINDOW_MS ? FIRST_INTERVAL_MS : SECOND_INTERVAL_MS;
		timer = setTimer( pollOnce, interval );
	};

	const reconcileError = async ( fallbackError ) => {
		if ( ! options.providerStatusUrl ) {
			return terminal( { status: 'failed', error: fallbackError } );
		}

		try {
			const providerStatus = await request( options.providerStatusUrl );
			const pending = providerStatus?.pendingConnection;

			if ( providerStatus?.reason === 'login_pending' && pending?.status === 'completed' ) {
				return terminal( {
					status: 'sync_retry',
					error: pending.error || fallbackError,
					providerStatus,
				} );
			}

			return terminal( {
				status: 'failed',
				error: pending?.error || fallbackError,
				providerStatus,
			} );
		} catch ( error ) {
			onError( error );
			return terminal( { status: 'failed', error: fallbackError } );
		}
	};

	async function pollOnce() {
		if ( polling || activePoll ) {
			if ( ! stopped && state.status === 'pending' ) {
				pollAgain = true;
			}

			return activePoll || Promise.resolve();
		}

		const pollPromise = Promise.resolve().then( async () => {
			polling = true;

			try {
				do {
					pollAgain = false;

					if ( stopped || state.status !== 'pending' ) {
						return;
					}

					if ( elapsed() >= TIMEOUT_MS ) {
						terminal( { status: 'timed_out' } );
						return;
					}

					try {
						const result = await request( options.statusUrl );
						const status = result?.status || 'pending';

						if ( status === 'connected' ) {
							terminal( {
								status: 'connected',
								connection: result.connection || null,
								catalog: result.catalog || result.snapshot || null,
								error: '',
							} );
							return;
						}

						if ( status === 'error' ) {
							await reconcileError( result.error || 'The local Codex runtime reported a login error.' );
							return;
						}

						if ( status === 'missing' ) {
							terminal( {
								status: 'missing',
								error: result.error || 'The local runtime no longer has this login session.',
							} );
							return;
						}

						emit( { status: 'pending', error: result.error || '' } );
					} catch ( error ) {
						onError( error );
						emit( {
							status: 'pending',
							error: toErrorMessage( error, 'Still checking connection status.' ),
						} );
					}
				} while ( pollAgain && ! stopped && state.status === 'pending' );
			} finally {
				polling = false;
				pollAgain = false;
				scheduleNextPoll();
			}
		} );

		activePoll = pollPromise;

		try {
			return await pollPromise;
		} finally {
			if ( activePoll === pollPromise ) {
				activePoll = null;
			}
		}
	}

	const pollNow = async () => {
		const wasTimedOut = state.status === 'timed_out';

		if ( state.status === 'timed_out' ) {
			stopped = false;
			startedAt = now();
			emit( { status: 'pending', error: '' } );
		}

		if ( activePoll ) {
			await activePoll;

			if ( wasTimedOut && state.status === 'pending' ) {
				return pollOnce();
			}
		}

		return pollOnce();
	};

	function resume() {
		if ( state.status !== 'pending' || polling || stopped ) {
			return;
		}

		void pollOnce();
	}

	function addResumeListeners() {
		removeResumeListeners();
		windowObject.addEventListener?.( 'focus', resume );
		windowObject.addEventListener?.( 'pageshow', resume );
		visibilityListener = () => {
			if ( ! documentObject?.visibilityState || documentObject.visibilityState === 'visible' ) {
				resume();
			}
		};
		documentObject?.addEventListener?.( 'visibilitychange', visibilityListener );
	}

	const resumePending = ( pendingState ) => {
		stopped = false;
		startedAt = now();
		clearScheduledPoll();
		removeResumeListeners();
		emit( {
			...INITIAL_STATE,
			...( pendingState || {} ),
			status: 'pending',
			copyStatus: 'idle',
			popupStatus: 'idle',
			error: pendingState?.error || '',
		} );
		addResumeListeners();
		scheduleNextPoll();
		return state;
	};

	const start = async () => {
		stopped = false;
		startedAt = now();
		clearScheduledPoll();
		removeResumeListeners();
		emit( { ...INITIAL_STATE, status: 'starting' } );

		const authWindow = windowObject.open?.( 'about:blank', 'codex-provider-auth' ) || null;
		const popupStatus = authWindow ? 'opened' : 'blocked';
		const prearmedClipboardCopy = createPrearmedClipboardCopy( windowObject, clipboard );

		try {
			const result = await request( options.startUrl, { method: 'POST' } );

			if ( result?.status === 'connected' ) {
				prearmedClipboardCopy?.cancel();

				if ( authWindow && ! authWindow.closed ) {
					try {
						authWindow.close();
					} catch ( closeError ) {
						onError( closeError );
					}
				}

				terminal( {
					status: 'connected',
					connection: result.connection || null,
					catalog: result.catalog || result.snapshot || null,
					error: '',
				} );
				return result;
			}

			let finalPopupStatus = popupStatus;
			if ( authWindow?.closed ) {
				finalPopupStatus = 'blocked';
			} else if ( authWindow ) {
				try {
					authWindow.opener = null;
					authWindow.location.href = result.verificationUrl;
					finalPopupStatus = 'navigated';
				} catch ( error ) {
					onError( error );
					finalPopupStatus = 'blocked';
				}
			}

			emit( {
				status: 'pending',
				authSessionId: result.authSessionId || '',
				verificationUrl: result.verificationUrl || '',
				userCode: result.userCode || '',
				copyStatus: 'unsupported',
				popupStatus: finalPopupStatus,
				error: '',
			} );

			if ( prearmedClipboardCopy && result.userCode ) {
				try {
					await prearmedClipboardCopy.copy( result.userCode );
					emit( { copyStatus: 'copied' } );
				} catch ( error ) {
					onError( error );
					emit( { copyStatus: 'failed' } );
				}
			} else if ( clipboard?.writeText && result.userCode ) {
				try {
					await clipboard.writeText( result.userCode );
					emit( { copyStatus: 'copied' } );
				} catch ( error ) {
					onError( error );
					emit( { copyStatus: 'failed' } );
				}
			}

			addResumeListeners();
			scheduleNextPoll();
			return result;
		} catch ( error ) {
			prearmedClipboardCopy?.cancel();

			if ( authWindow && ! authWindow.closed ) {
				try {
					authWindow.close();
				} catch ( closeError ) {
					onError( closeError );
				}
			}

			const message = toErrorMessage( error, 'The local Codex runtime request failed.' );
			onError( error );
			terminal( { status: 'failed', error: message, popupStatus } );
			return null;
		}
	};

	return {
		getState: () => state,
		resumePending,
		start,
		pollNow,
		stop,
	};
}
