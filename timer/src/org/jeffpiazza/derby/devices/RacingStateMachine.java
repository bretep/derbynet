package org.jeffpiazza.derby.devices;

// Despite differences between different timers, the basic state machine to
import jssc.SerialPortException;
import org.jeffpiazza.derby.LogWriter;

// model them is essentially the same.
public class RacingStateMachine {
  public enum State {
    // After discovery, or after a race finishes, the track is IDLE.
    IDLE,
    // After prepareHeat and lane mask has been applied: MARK
    MARK,
    // After lane mask applied and gate closes, get SET to race.
    SET,
    // When the gate then opens, the race is RUNNING.
    RUNNING,
    // If the race starts but no results received within maxRunningTimeLimit,
    // then transition to RESULTS_OVERDUE state.
    RESULTS_OVERDUE;
  }

  // Detectable external events that may change the state we believe we're in.
  //
  // Note that there's no LOST_CONNECTION, because if that happens, we're
  // going to abandon the state machine and everything else altogether.
  public enum Event {
    PREPARE_HEAT_RECEIVED,
    ABORT_HEAT_RECEIVED,
    GATE_OPENED,
    GATE_CLOSED,
    // From a state machine perspective, "results overdue" is treated like
    // RESULTS_RECEIVED, although we likely report different messages to the
    // server for these cases.
    RESULTS_RECEIVED;
  }

  public interface TransitionCallback {
    public void onTransition(State oldState, State newState)
        throws SerialPortException;
  }

  private LogWriter logWriter;
  private long stateEnteredMillis = 0;

  public RacingStateMachine(LogWriter logWriter) {
    this.logWriter = logWriter;
  }

  // Number of milliseconds we've been in the current state
  public long millisInCurrentState() {
    return System.currentTimeMillis() - stateEnteredMillis;
  }

  // Maximum amount of time, in milliseconds, that we're allowed to remain in
  // the RUNNING state.  If 0, then there's no limit.
  private long maxRunningTimeLimit = 0;

  public void setMaxRunningTimeLimit(long maxRunningTimeLimit) {
    this.maxRunningTimeLimit = maxRunningTimeLimit;
  }

  private State currentState = State.IDLE;

  // Reads the current state.  May advance RUNNING to RESULTS_OVERDUE state
  // if we've been waiting too long.
  public synchronized State state(TransitionCallback cb)
      throws SerialPortException {
    if (currentState == State.RUNNING && maxRunningTimeLimit > 0
        && System.currentTimeMillis() > stateEnteredMillis + maxRunningTimeLimit) {
      currentState = State.RESULTS_OVERDUE;
      stateEnteredMillis = System.currentTimeMillis();
      if (cb != null) {
        cb.onTransition(State.RUNNING, currentState);
      }
    }
    return currentState;
  }

  public synchronized State onEvent(Event e, TransitionCallback cb)
      throws SerialPortException {
    State initialState = currentState;
    switch (currentState) {
      case IDLE:
        switch (e) {
          case PREPARE_HEAT_RECEIVED:
            currentState = State.MARK;
            break;
          case ABORT_HEAT_RECEIVED:
            currentState = unexpected(e, State.IDLE);
            break;
          case RESULTS_RECEIVED:
            currentState = unexpected(e, State.IDLE);
            break;
        }
        break;
      case MARK:
        switch (e) {
          case GATE_CLOSED:
            currentState = State.SET;
            break;
          case ABORT_HEAT_RECEIVED:
            currentState = State.IDLE;
            break;
          case PREPARE_HEAT_RECEIVED:
            currentState = unexpected(e, State.MARK);
            break;
          case RESULTS_RECEIVED:
            currentState = unexpected(e, State.IDLE);
            break;
        }
        break;
      case SET:
        switch (e) {
          case GATE_OPENED:
            currentState = State.RUNNING;
            break;
          case ABORT_HEAT_RECEIVED:
            currentState = State.IDLE;
            break;
          case PREPARE_HEAT_RECEIVED:
            currentState = unexpected(e, State.SET);
            break;
          case GATE_CLOSED:
            currentState = unexpected(e, State.SET);
            break;
          case RESULTS_RECEIVED:
            currentState = unexpected(e, State.IDLE);
            break;
        }
        break;
      case RUNNING:
      case RESULTS_OVERDUE:
        // State machine behavior doesn't distinguish between
        // RUNNING and RESULTS_OVERDUE, but other entities do.
        switch (e) {
          case RESULTS_RECEIVED:
            currentState = State.IDLE;
            break;
          case ABORT_HEAT_RECEIVED:
            currentState = State.IDLE;
            break;
          case PREPARE_HEAT_RECEIVED:
            currentState = unexpected(e, State.MARK);
            break;
          case GATE_OPENED:
            currentState = unexpected(e, State.RUNNING);
            break;
          // One could imagine receiving a GATE_CLOSED (affecting the start
          // gate) while the race is running and the timer is still going.
        }
    }
    if (initialState != currentState) {
      stateEnteredMillis = System.currentTimeMillis();
      if (cb != null) {
        cb.onTransition(initialState, currentState);
      }
    }
    // System.out.println(initialState + " >--" + e + "--> " + currentState);
    return currentState;
  }

  private State unexpected(Event e, State next) {
    logWriter.serialPortLogInternal(
        "Unexpected event: " + currentState + " >--" + e + "--> " + next);
    return next;
  }
}
