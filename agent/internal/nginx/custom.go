package nginx

import (
	"fmt"
	"regexp"
	"strings"
	"unicode"
	"unicode/utf8"

	"commypanel/agent/internal/validate"
)

// Custom directives are the one piece of nginx config a user may write. nginx
// runs its master process as root, so the text is never trusted or embedded
// as typed: it is parsed, every directive and argument is checked against an
// allow-list, and the config is rendered from the parsed result. Anything not
// on the list (root, alias, include, access_log, ssl_*, proxy_pass to a
// foreign address, ...) is refused, because it could read or write host files
// or reach services the app has no business with.
//
// The snippet lives inside the app's serving server block. return, rewrite
// and if are only allowed inside a location, because at server level they
// run before location matching and would capture the Let's Encrypt validation
// requests (the generated ACME location is `^~`, so it wins over any
// location the user adds).

const (
	maxCustomBytes  = 16 << 10
	maxCustomNodes  = 200
	maxCustomDepth  = 3
	maxCustomErrors = 5

	// upstreamVar is set by the generated config to the live container's
	// address; it is the only value proxy_pass may use.
	upstreamVar = "$commypanel_upstream"
)

type argument struct {
	text   string
	quoted bool
}

type node struct {
	line     int
	comment  string // for a comment node; name is empty
	name     string
	args     []argument
	block    []node
	hasBlock bool
}

// CheckCustom validates a custom-directives snippet and returns the text to
// put into the server block (indented four spaces, comments kept). An empty
// snippet is valid and renders nothing.
func CheckCustom(text string) (string, error) {
	text = strings.ReplaceAll(text, "\r\n", "\n")
	if strings.TrimSpace(text) == "" {
		return "", nil
	}
	nodes, err := parseCustom(text)
	if err != nil {
		return "", err
	}
	if errs := validateNodes(nodes, ctxServer); len(errs) > 0 {
		more := ""
		if len(errs) > maxCustomErrors {
			errs = errs[:maxCustomErrors]
			more = "\n(more problems may follow once these are fixed)"
		}
		return "", &validate.InputError{Msg: strings.Join(errs, "\n") + more}
	}
	return emit(nodes, 1), nil
}

// --- parser ------------------------------------------------------------------

type parser struct {
	src   []rune
	pos   int
	line  int
	nodes int
	cond  bool // reading the condition of an if, where an unbalanced ) ends a word
}

func parseCustom(text string) ([]node, error) {
	if len(text) > maxCustomBytes {
		return nil, &validate.InputError{Msg: fmt.Sprintf("the custom directives are too long (the limit is %d KB)", maxCustomBytes>>10)}
	}
	if !utf8.ValidString(text) {
		return nil, &validate.InputError{Msg: "the custom directives are not valid text"}
	}
	p := &parser{src: []rune(text), line: 1}
	nodes, err := p.block(0)
	if err != nil {
		return nil, &validate.InputError{Msg: err.Error()}
	}
	return nodes, nil
}

func (p *parser) errf(format string, args ...any) error {
	return fmt.Errorf("line %d: %s", p.line, fmt.Sprintf(format, args...))
}

func (p *parser) eof() bool  { return p.pos >= len(p.src) }
func (p *parser) peek() rune { return p.src[p.pos] }

func (p *parser) skipSpace() {
	for !p.eof() {
		switch c := p.peek(); {
		case c == '\n':
			p.line++
			p.pos++
		case c == ' ' || c == '\t':
			p.pos++
		default:
			return
		}
	}
}

func (p *parser) comment() string {
	p.pos++ // '#'
	start := p.pos
	for !p.eof() && p.peek() != '\n' {
		p.pos++
	}
	clean := strings.Map(func(r rune) rune {
		if isBadRune(r) {
			return -1
		}
		return r
	}, string(p.src[start:p.pos]))
	return strings.TrimSpace(clean)
}

func (p *parser) block(depth int) ([]node, error) {
	var out []node
	for {
		p.skipSpace()
		if p.eof() {
			if depth > 0 {
				return nil, p.errf("a block is missing its closing }")
			}
			return out, nil
		}
		if p.nodes++; p.nodes > maxCustomNodes {
			return nil, p.errf("too many directives (the limit is %d)", maxCustomNodes)
		}
		switch c := p.peek(); {
		case c == '}':
			if depth == 0 {
				return nil, p.errf("unexpected }")
			}
			p.pos++
			return out, nil
		case c == '#':
			line := p.line
			out = append(out, node{line: line, comment: p.comment()})
		default:
			n, err := p.statement(depth)
			if err != nil {
				return nil, err
			}
			out = append(out, n)
		}
	}
}

func (p *parser) statement(depth int) (node, error) {
	n := node{line: p.line}
	start := p.pos
	for !p.eof() && isWordRune(p.peek(), p.pos == start) {
		p.pos++
	}
	if p.pos == start {
		return n, p.errf("unexpected %q", string(p.peek()))
	}
	n.name = string(p.src[start:p.pos])

	if n.name == "if" {
		return p.ifStatement(n, depth)
	}
	for {
		p.skipSpace()
		if p.eof() {
			return n, fmt.Errorf("line %d: %q is missing its closing ;", n.line, n.name)
		}
		switch c := p.peek(); {
		case c == ';':
			p.pos++
			return n, nil
		case c == '{':
			p.pos++
			return p.openBlock(n, depth)
		case c == '}':
			return n, fmt.Errorf("line %d: %q is missing its closing ;", n.line, n.name)
		case c == '#':
			p.comment() // a comment between arguments is dropped
		default:
			a, err := p.token()
			if err != nil {
				return n, err
			}
			n.args = append(n.args, a)
		}
	}
}

func (p *parser) openBlock(n node, depth int) (node, error) {
	if depth+1 > maxCustomDepth {
		return n, p.errf("blocks are nested too deeply")
	}
	children, err := p.block(depth + 1)
	if err != nil {
		return n, err
	}
	n.block, n.hasBlock = children, true
	return n, nil
}

// ifStatement parses `if (condition) { ... }`. The condition is cut out by
// its own parentheses, because nginx lets them touch the words inside.
func (p *parser) ifStatement(n node, depth int) (node, error) {
	p.skipSpace()
	if p.eof() || p.peek() != '(' {
		return n, p.errf("\"if\" needs a condition in parentheses")
	}
	p.pos++
	p.cond = true
	defer func() { p.cond = false }()
	for {
		p.skipSpace()
		if p.eof() {
			return n, p.errf("the condition of \"if\" is missing its closing )")
		}
		c := p.peek()
		if c == ')' {
			p.pos++
			break
		}
		if c == ';' || c == '{' || c == '}' {
			return n, p.errf("the condition of \"if\" is missing its closing )")
		}
		a, err := p.token()
		if err != nil {
			return n, err
		}
		n.args = append(n.args, a)
	}
	p.cond = false
	p.skipSpace()
	if p.eof() || p.peek() != '{' {
		return n, p.errf("\"if\" needs a block in braces")
	}
	p.pos++
	return p.openBlock(n, depth)
}

// token reads one argument. Escapes follow nginx: \" \' \\ stand for the
// character, \t \r \n are control characters (refused below), and any other
// backslash stays as it is, which is what regular expressions need.
func (p *parser) token() (argument, error) {
	var sb strings.Builder
	depth := 0 // parentheses opened inside a bare word of an if condition
	quote := rune(0)
	if c := p.peek(); c == '"' || c == '\'' {
		quote = c
		p.pos++
	}
	for {
		if p.eof() {
			if quote != 0 {
				return argument{}, p.errf("a quote is not closed")
			}
			break
		}
		c := p.peek()
		if c == '\n' && quote != 0 {
			return argument{}, p.errf("a quoted value cannot span lines")
		}
		if quote == 0 {
			if c == ' ' || c == '\t' || c == '\n' || c == ';' || c == '{' || c == '}' {
				break
			}
			if p.cond {
				if c == '(' {
					depth++
				} else if c == ')' {
					if depth == 0 {
						break
					}
					depth--
				}
			}
			if c == '"' || c == '\'' {
				return argument{}, p.errf("a quote must start a value, not appear inside one")
			}
		} else if c == quote {
			p.pos++
			if !p.eof() {
				if next := p.peek(); next != ' ' && next != '\t' && next != '\n' && next != ';' && next != '{' && !(p.cond && next == ')') {
					return argument{}, p.errf("unexpected %q after a closing quote", string(next))
				}
			}
			return finishToken(&sb, true, p)
		}
		if c == '\\' {
			if p.pos+1 >= len(p.src) {
				return argument{}, p.errf("a backslash cannot end the text")
			}
			switch next := p.src[p.pos+1]; next {
			case '"', '\'', '\\':
				sb.WriteRune(next)
				p.pos += 2
				continue
			case 't', 'r', 'n':
				return argument{}, p.errf("control characters are not allowed")
			}
		}
		sb.WriteRune(c)
		p.pos++
	}
	return finishToken(&sb, false, p)
}

func finishToken(sb *strings.Builder, quoted bool, p *parser) (argument, error) {
	text := sb.String()
	for _, r := range text {
		if isBadRune(r) {
			return argument{}, p.errf("control characters are not allowed")
		}
	}
	return argument{text: text, quoted: quoted}, nil
}

func isWordRune(r rune, first bool) bool {
	return r == '_' || r >= 'a' && r <= 'z' || r >= 'A' && r <= 'Z' || !first && r >= '0' && r <= '9'
}

func isBadRune(r rune) bool {
	return unicode.IsControl(r) || r == 0x2028 || r == 0x2029 || r == 0xFEFF
}

// --- validation ---------------------------------------------------------------

type scope uint8

const (
	ctxServer scope = 1 << iota
	ctxLocation
	ctxIf
)

func (c scope) String() string {
	switch c {
	case ctxServer:
		return "at the top level"
	case ctxLocation:
		return "inside a location"
	default:
		return "inside an if"
	}
}

type spec struct {
	where    scope // the contexts the directive may appear in
	min, max int
	check    func(name string, args []argument) string // returns a problem, or ""
	block    bool
}

var (
	sizeRe    = regexp.MustCompile(`^\d{1,9}[kKmMgG]?$`)
	timeRe    = regexp.MustCompile(`^\d{1,9}(ms|s|m|h|d|w|M|y)?$`)
	headerRe  = regexp.MustCompile(`^[A-Za-z0-9-]{1,64}$`)
	mimeRe    = regexp.MustCompile(`^[A-Za-z0-9!#$&^_.+*/-]{1,100}$`)
	codeRe    = regexp.MustCompile(`^=?\d{0,3}$`)
	ipRe      = regexp.MustCompile(`^(all|[0-9a-fA-F:.]{2,45}(/\d{1,3})?)$`)
	varRe     = regexp.MustCompile(`^\$[A-Za-z_][A-Za-z0-9_]{0,63}$`)
	statusRe  = regexp.MustCompile(`^[1-5]\d\d$`)
	numberRe  = regexp.MustCompile(`^\d{1,4}$`)
	wordRe    = regexp.MustCompile(`^[A-Za-z0-9_.-]{1,64}$`)
	rewriteFl = map[string]bool{"last": true, "break": true, "redirect": true, "permanent": true}
)

func each(re *regexp.Regexp, what string) func(string, []argument) string {
	return func(name string, args []argument) string {
		for _, a := range args {
			if !re.MatchString(a.text) {
				return fmt.Sprintf("%q: %q is not %s", name, a.text, what)
			}
		}
		return ""
	}
}

func onOff(name string, args []argument) string {
	if args[0].text != "on" && args[0].text != "off" {
		return fmt.Sprintf("%q takes on or off", name)
	}
	return ""
}

func anyValue(string, []argument) string { return "" }

var specs map[string]spec

func init() {
	both := ctxServer | ctxLocation
	size := each(sizeRe, "a size such as 50m")
	dur := each(timeRe, "a time such as 120s")
	specs = map[string]spec{
		"client_max_body_size":      {both, 1, 1, size, false},
		"client_body_buffer_size":   {both, 1, 1, size, false},
		"client_header_buffer_size": {ctxServer, 1, 1, size, false},
		"large_client_header_buffers": {ctxServer, 2, 2, func(n string, a []argument) string {
			if !numberRe.MatchString(a[0].text) || !sizeRe.MatchString(a[1].text) {
				return fmt.Sprintf("%q takes a count and a size, for example 4 16k", n)
			}
			return ""
		}, false},
		"client_body_timeout":      {both, 1, 1, dur, false},
		"client_header_timeout":    {ctxServer, 1, 1, dur, false},
		"send_timeout":             {both, 1, 1, dur, false},
		"keepalive_timeout":        {both, 1, 2, dur, false},
		"proxy_connect_timeout":    {both, 1, 1, dur, false},
		"proxy_read_timeout":       {both, 1, 1, dur, false},
		"proxy_send_timeout":       {both, 1, 1, dur, false},
		"proxy_buffering":          {both, 1, 1, onOff, false},
		"proxy_request_buffering":  {both, 1, 1, onOff, false},
		"proxy_intercept_errors":   {both, 1, 1, onOff, false},
		"proxy_buffer_size":        {both, 1, 1, size, false},
		"proxy_busy_buffers_size":  {both, 1, 1, size, false},
		"proxy_max_temp_file_size": {both, 1, 1, size, false},
		"proxy_buffers": {both, 2, 2, func(n string, a []argument) string {
			if !numberRe.MatchString(a[0].text) || !sizeRe.MatchString(a[1].text) {
				return fmt.Sprintf("%q takes a count and a size, for example 8 16k", n)
			}
			return ""
		}, false},
		"gzip":            {both, 1, 1, onOff, false},
		"gzip_vary":       {both, 1, 1, onOff, false},
		"gzip_min_length": {both, 1, 1, size, false},
		"gzip_comp_level": {both, 1, 1, func(n string, a []argument) string {
			if len(a[0].text) != 1 || a[0].text[0] < '1' || a[0].text[0] > '9' {
				return fmt.Sprintf("%q takes a level from 1 to 9", n)
			}
			return ""
		}, false},
		"gzip_types":   {both, 1, 40, each(mimeRe, "a content type"), false},
		"gzip_proxied": {both, 1, 10, each(wordRe, "a valid value"), false},
		"add_header": {both | ctxIf, 2, 3, func(n string, a []argument) string {
			if !headerRe.MatchString(a[0].text) {
				return fmt.Sprintf("%q: %q is not a header name", n, a[0].text)
			}
			if len(a) == 3 && a[2].text != "always" {
				return fmt.Sprintf("%q: the last argument can only be always", n)
			}
			return ""
		}, false},
		"expires": {both, 1, 3, anyValue, false},
		"error_page": {both, 2, 8, func(n string, a []argument) string {
			for _, c := range a[:len(a)-1] {
				if !codeRe.MatchString(c.text) || c.text == "" {
					return fmt.Sprintf("%q: %q is not a status code", n, c.text)
				}
			}
			last := a[len(a)-1].text
			if !strings.HasPrefix(last, "/") && !strings.HasPrefix(last, "http://") && !strings.HasPrefix(last, "https://") {
				return fmt.Sprintf("%q must end with a path starting with / or a full http(s) address", n)
			}
			return ""
		}, false},
		"allow":                  {both, 1, 1, each(ipRe, "an address, a network or all"), false},
		"deny":                   {both, 1, 1, each(ipRe, "an address, a network or all"), false},
		"set_real_ip_from":       {ctxServer, 1, 1, each(ipRe, "an address or a network"), false},
		"real_ip_header":         {ctxServer, 1, 1, each(headerRe, "a header name"), false},
		"real_ip_recursive":      {ctxServer, 1, 1, onOff, false},
		"server_tokens":          {ctxServer, 1, 1, each(wordRe, "a valid value"), false},
		"charset":                {both, 1, 1, each(wordRe, "a character set"), false},
		"merge_slashes":          {ctxServer, 1, 1, onOff, false},
		"underscores_in_headers": {ctxServer, 1, 1, onOff, false},
		"ignore_invalid_headers": {ctxServer, 1, 1, onOff, false},
		"proxy_hide_header":      {both, 1, 1, each(headerRe, "a header name"), false},
		"set": {both | ctxIf, 2, 2, func(n string, a []argument) string {
			if a[0].quoted || !varRe.MatchString(a[0].text) {
				return fmt.Sprintf("%q: %q is not a variable name such as $mode", n, a[0].text)
			}
			if strings.HasPrefix(a[0].text, "$commypanel") {
				return fmt.Sprintf("%q: variables starting with $commypanel are reserved", n)
			}
			return ""
		}, false},
		"proxy_set_header": {ctxLocation, 2, 2, func(n string, a []argument) string {
			if !headerRe.MatchString(a[0].text) {
				return fmt.Sprintf("%q: %q is not a header name", n, a[0].text)
			}
			return ""
		}, false},
		"proxy_redirect": {ctxLocation, 1, 2, anyValue, false},
		"proxy_pass": {ctxLocation, 1, 1, func(n string, a []argument) string {
			if a[0].text != upstreamVar {
				return fmt.Sprintf("%q can only be %s, which is the address of this app", n, upstreamVar)
			}
			return ""
		}, false},
		"return": {ctxLocation | ctxIf, 1, 2, func(n string, a []argument) string {
			if !statusRe.MatchString(a[0].text) {
				return fmt.Sprintf("%q must start with a status code such as 301, then the target (for example: return 301 /new;)", n)
			}
			return ""
		}, false},
		"rewrite": {ctxLocation | ctxIf, 2, 3, func(n string, a []argument) string {
			if len(a) == 3 && !rewriteFl[a[2].text] {
				return fmt.Sprintf("%q: the last argument must be last, break, redirect or permanent", n)
			}
			return ""
		}, false},
		"location": {ctxServer, 1, 2, checkLocation, true},
		"if":       {ctxLocation, 1, 3, checkIf, true},
	}
}

var locationMods = map[string]bool{"=": true, "~": true, "~*": true, "^~": true}

func checkLocation(name string, args []argument) string {
	uri, mod := args[len(args)-1].text, ""
	if len(args) == 2 {
		mod = args[0].text
		if !locationMods[mod] {
			return fmt.Sprintf("%q: %q is not a location modifier (use =, ~, ~* or ^~)", name, mod)
		}
	}
	regex := mod == "~" || mod == "~*"
	switch {
	case uri == "":
		return "\"location\" needs a path"
	case !regex && !strings.HasPrefix(uri, "/"):
		return fmt.Sprintf("%q: the path must start with / (named locations are not supported)", name)
	case !regex && uri == "/" && (mod == "" || mod == "="):
		return "the app's main location / is generated by CommyPanel; use a more specific path"
	}
	return ""
}

var ifOps = map[string]bool{"=": true, "!=": true, "~": true, "~*": true, "!~": true, "!~*": true}

func checkIf(name string, args []argument) string {
	if args[0].quoted || !varRe.MatchString(args[0].text) {
		return "an \"if\" condition must start with a variable such as $http_user_agent (file tests are not supported)"
	}
	if len(args) == 2 || len(args) == 3 && !ifOps[args[1].text] {
		return "an \"if\" condition is ($variable), or ($variable operator value) with =, !=, ~, ~*, !~ or !~*"
	}
	return ""
}

func validateNodes(nodes []node, in scope) []string {
	var errs []string
	for _, n := range nodes {
		if n.name == "" {
			continue // a comment
		}
		sp, ok := specs[n.name]
		switch {
		case !ok:
			errs = append(errs, fmt.Sprintf("line %d: %q is not supported here. Only a fixed list of safe directives is accepted", n.line, n.name))
			continue
		case sp.where&in == 0:
			errs = append(errs, fmt.Sprintf("line %d: %q is not allowed %s; it works %s", n.line, n.name, in, scopeNames(sp.where)))
			continue
		case len(n.args) < sp.min || len(n.args) > sp.max:
			errs = append(errs, fmt.Sprintf("line %d: %q takes %s", n.line, n.name, arity(sp.min, sp.max)))
			continue
		case sp.block != n.hasBlock:
			if sp.block {
				errs = append(errs, fmt.Sprintf("line %d: %q needs a block in { }", n.line, n.name))
			} else {
				errs = append(errs, fmt.Sprintf("line %d: %q does not take a block", n.line, n.name))
			}
			continue
		}
		if msg := sp.check(n.name, n.args); msg != "" {
			errs = append(errs, fmt.Sprintf("line %d: %s", n.line, msg))
			continue
		}
		if sp.block {
			next := ctxLocation
			if n.name == "if" {
				next = ctxIf
			}
			errs = append(errs, validateNodes(n.block, next)...)
		}
	}
	return errs
}

func scopeNames(c scope) string {
	var parts []string
	for _, w := range []scope{ctxServer, ctxLocation, ctxIf} {
		if c&w != 0 {
			parts = append(parts, w.String())
		}
	}
	return strings.Join(parts, " or ")
}

func arity(min, max int) string {
	switch {
	case min == max && min == 1:
		return "one value"
	case min == max:
		return fmt.Sprintf("%d values", min)
	default:
		return fmt.Sprintf("%d to %d values", min, max)
	}
}

// --- output -------------------------------------------------------------------

var bareRe = regexp.MustCompile(`^[A-Za-z0-9_./:@%+=~*^$|,?!&()\[\]-]+$`)

func quote(a argument) string {
	if bareRe.MatchString(a.text) {
		return a.text
	}
	s := strings.ReplaceAll(a.text, `\`, `\\`)
	return `"` + strings.ReplaceAll(s, `"`, `\"`) + `"`
}

func emit(nodes []node, level int) string {
	var sb strings.Builder
	pad := strings.Repeat("    ", level)
	for _, n := range nodes {
		switch {
		case n.name == "":
			sb.WriteString(strings.TrimRight(pad+"# "+n.comment, " ") + "\n")
		case n.name == "if":
			parts := make([]string, len(n.args))
			for i, a := range n.args {
				parts[i] = quote(a)
			}
			fmt.Fprintf(&sb, "%sif (%s) {\n%s%s}\n", pad, strings.Join(parts, " "), emit(n.block, level+1), pad)
		default:
			parts := []string{n.name}
			for _, a := range n.args {
				parts = append(parts, quote(a))
			}
			if n.hasBlock {
				fmt.Fprintf(&sb, "%s%s {\n%s%s}\n", pad, strings.Join(parts, " "), emit(n.block, level+1), pad)
			} else {
				fmt.Fprintf(&sb, "%s%s;\n", pad, strings.Join(parts, " "))
			}
		}
	}
	return sb.String()
}
